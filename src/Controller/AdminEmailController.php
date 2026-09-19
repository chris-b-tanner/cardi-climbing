<?php

namespace App\Controller;

use App\Entity\Certification;
use App\Entity\Email;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\CertificationRepository;
use App\Repository\EmailRepository;
use App\Repository\EventRepository;
use App\Repository\NoteRepository;
use App\Repository\TagRepository;
use App\Repository\UserCertificationRepository;
use App\Repository\UserRepository;
use App\Message\SendBulkEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * Everything to do with admin-sent email lives here — one controller, one template
 * (admin/email/compose.html.twig) for composing, editing a draft, and viewing a sent email.
 * There used to be two near-identical tools (a compose-and-send-immediately screen, and a
 * separate drafting workflow limited to the general/tag-filtered audience); this replaces both.
 *
 * Every email — whatever its audience (a single member, an event's attendees, a certification's
 * holders, a tag-filtered group, or the whole opted-in membership) — is now saved as a draft
 * first; only a saved draft can be sent (see save()/send()). Composing/saving/sending is open to
 * any team member; targeting the whole membership still requires ROLE_ADMIN (same restriction
 * that always applied). Browsing every past email and flagging one as a reusable template stay
 * admin-only, under /admin/settings.
 */
#[IsGranted('ROLE_TEAM')]
class AdminEmailController extends AbstractController
{
    /**
     * Starts (no {id}) or resumes/views (with {id}) an email. A fixed audience for a brand-new
     * one is read from the query string (eventId/certificationId/userId — the "Email" buttons on
     * those pages link here); for an existing one it's read back from what was saved.
     */
    #[Route('/admin/email/compose', name: 'app_admin_email_compose', methods: ['GET'])]
    #[Route('/admin/email/compose/{id}', name: 'app_admin_email_compose_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function compose(
        Request $request,
        ?int $id,
        EmailRepository $emailRepository,
        TagRepository $tagRepository,
        UserRepository $userRepository,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
        NoteRepository $noteRepository,
    ): Response {
        $email = $id ? $emailRepository->find($id) : new Email();
        if (!$email) {
            throw $this->createNotFoundException();
        }

        [$eventAudience, $certificationAudience, $userAudience] = $this->resolveDisplayAudience(
            $request, $email, $eventRepository, $attendeeRepository, $certificationRepository, $userCertificationRepository, $userRepository,
        );

        return $this->render('admin/email/compose.html.twig', [
            'email'                  => $email,
            'tags'                   => $tagRepository->findBy([], ['name' => 'ASC']),
            'totalOptedIn'           => count($userRepository->findForBulkEmail()),
            'templates'              => $emailRepository->findTemplates(),
            'eventAudience'          => $eventAudience,
            'certificationAudience'  => $certificationAudience,
            'userAudience'           => $userAudience,
            // Sending is queued (see send()/SendBulkEmailMessageHandler), so sentCount is how many
            // were queued, not how many have actually gone out yet — this is the confirmed count.
            'deliveredCount'         => $email->getId() ? $noteRepository->countForEmail($email->getId()) : 0,
        ]);
    }

    #[Route('/admin/email/preview', name: 'app_admin_email_preview', methods: ['POST'])]
    public function preview(Request $request, UserRepository $userRepository, Environment $twig): Response
    {
        $context = [
            'subject' => $request->request->get('subject', '(No subject)'),
            'body'    => $request->request->get('body', ''),
        ];

        // Only a single-member-scoped send has one definite recipient to greet by name in the
        // preview — an event/certification/tag audience has many different first names, so the
        // greeting stays generic ("Hi,") for those, same as before.
        $userId = (int) $request->request->get('userId', 0);
        if ($userId) {
            $user = $userRepository->find($userId);
            if ($user instanceof User) {
                $context['user'] = $user;
            }
        }

        $useBlankLayout = $request->request->getBoolean('useBlankLayout');
        $html = $twig->render($useBlankLayout ? 'email/bulk_blank.html.twig' : 'email/bulk.html.twig', $context);

        return new Response($html);
    }

    #[Route('/admin/email/count', name: 'app_admin_email_count', methods: ['POST'])]
    public function count(Request $request, UserRepository $userRepository): Response
    {
        $tagIds = array_map('intval', array_filter($request->request->all('tagIds')));
        $count  = count($userRepository->findForBulkEmail($tagIds));

        return new Response((string) $count);
    }

    /** Content for the "load from template" picker — 404s for anything not flagged as a template, so a compose user can't fetch an arbitrary email's content just by guessing its id. */
    #[Route('/admin/email/template-content/{id}', name: 'app_admin_email_template_content', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function templateContent(Email $email): JsonResponse
    {
        if (!$email->isTemplate()) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse([
            'subject'        => $email->getSubject(),
            'body'           => $email->getBody(),
            'useBlankLayout' => $email->isUseBlankLayout(),
        ]);
    }

    /**
     * Creates or updates a draft — never sends anything. On a validation error, re-renders the
     * compose form directly (rather than redirecting) so nothing the user typed is lost.
     */
    #[Route('/admin/email/compose/save', name: 'app_admin_email_save', methods: ['POST'])]
    #[Route('/admin/email/compose/{id}/save', name: 'app_admin_email_save_existing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(
        Request $request,
        ?int $id,
        EmailRepository $emailRepository,
        TagRepository $tagRepository,
        UserRepository $userRepository,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
        EntityManagerInterface $em,
    ): Response {
        $isNew = $id === null;
        $email = $isNew ? new Email() : $emailRepository->find($id);

        if (!$email) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('email_draft', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$isNew && !$email->isDraft()) {
            $this->addFlash('error', 'Only a draft can be edited.');
            return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
        }

        $eventAudience = $this->resolveEventAudience(
            (int) $request->request->get('eventId', 0),
            (string) $request->request->get('scope', 'date'),
            (string) $request->request->get('occurrenceDate', ''),
            $eventRepository,
            $attendeeRepository,
        );
        $certificationAudience = $eventAudience ? null : $this->resolveCertificationAudience(
            (int) $request->request->get('certificationId', 0),
            $certificationRepository,
            $userCertificationRepository,
        );
        $userAudience = ($eventAudience || $certificationAudience) ? null : $this->resolveUserAudience(
            (int) $request->request->get('userId', 0),
            $userRepository,
        );

        $subject = trim($request->request->get('subject', ''));
        $body    = trim($request->request->get('body', ''));
        $error   = null;

        if (!$subject || !$body) {
            $error = 'Subject and body are required.';
        }

        $hasFixedAudience = (bool) ($eventAudience || $certificationAudience || $userAudience);
        $tagIds           = [];
        $wantsAll         = true;

        if (!$hasFixedAudience) {
            $tagIds   = array_map('intval', array_filter($request->request->all('tagIds')));
            $wantsAll = $request->request->get('audienceType', Email::AUDIENCE_ALL) !== Email::AUDIENCE_TAGS;

            if (!$error && !$wantsAll && !$tagIds) {
                $error = 'Choose at least one tag, or switch to "All opted-in members".';
            } elseif (!$error && $wantsAll && !$this->isGranted('ROLE_ADMIN')) {
                // Same restriction the tool has always had: only an admin may target the whole
                // opted-in membership — everyone else has to narrow to a tag.
                $error = 'Only an admin can draft an email to the whole membership — choose a tag instead.';
            }
        }

        if ($error) {
            $this->addFlash('error', $error);
            $email->setSubject($subject);
            $email->setBody($body);
            $email->setUseBlankLayout($request->request->getBoolean('useBlankLayout'));
            if (!$hasFixedAudience) {
                // Not persisted (no flush below) — only feeds the redisplayed form so the
                // tag/audience choice the user just made isn't lost on a validation error.
                $email->setAudienceType($wantsAll ? Email::AUDIENCE_ALL : Email::AUDIENCE_TAGS);
                $email->setAudienceParams(['tagIds' => $tagIds]);
            }

            return $this->render('admin/email/compose.html.twig', [
                'email'                  => $email,
                'tags'                   => $tagRepository->findBy([], ['name' => 'ASC']),
                'totalOptedIn'           => count($userRepository->findForBulkEmail()),
                'templates'              => $emailRepository->findTemplates(),
                'eventAudience'          => $eventAudience,
                'certificationAudience'  => $certificationAudience,
                'userAudience'           => $userAudience,
            ]);
        }

        [$audienceType, $audienceParams, $audienceLabel] = $this->resolveAudienceMeta(
            $eventAudience, $certificationAudience, $userAudience, $tagIds, $tagRepository, $userRepository,
        );

        $email->setSubject($subject);
        $email->setBody($body);
        $email->setUseBlankLayout($request->request->getBoolean('useBlankLayout'));
        $email->setAudienceType($audienceType);
        $email->setAudienceParams($audienceParams);
        $email->setAudienceLabel($audienceLabel);
        $email->touch();

        if ($isNew) {
            /** @var User $author */
            $author = $this->getUser();
            $email->setCreatedBy($author);
            $em->persist($email);
        }

        $em->flush();

        $this->addFlash('success', $isNew ? 'Draft saved.' : 'Draft updated.');
        return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
    }

    /** Draft → sent — actually dispatches the email to its resolved audience. Recipients are re-resolved fresh here (not reused from save time), since bookings/tags/membership can change between saving and sending. */
    #[Route('/admin/email/compose/{id}/send', name: 'app_admin_email_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(
        Request $request,
        Email $email,
        UserRepository $userRepository,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
        MessageBusInterface $bus,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('send_email_' . $email->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$email->isDraft()) {
            $this->addFlash('error', 'This email has already been sent.');
            return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
        }

        $params = $email->getAudienceParams() ?? [];

        $recipients = match ($email->getAudienceType()) {
            Email::AUDIENCE_EVENT => $this->resolveEventAudience(
                (int) ($params['eventId'] ?? 0),
                (string) ($params['scope'] ?? 'date'),
                (string) ($params['occurrenceDate'] ?? ''),
                $eventRepository,
                $attendeeRepository,
            )['recipients'] ?? [],
            Email::AUDIENCE_CERTIFICATION => $this->resolveCertificationAudience(
                (int) ($params['certificationId'] ?? 0),
                $certificationRepository,
                $userCertificationRepository,
            )['recipients'] ?? [],
            Email::AUDIENCE_USER => $this->resolveUserAudience((int) ($params['userId'] ?? 0), $userRepository)['recipients'] ?? [],
            Email::AUDIENCE_TAGS => $userRepository->findForBulkEmail($email->getTagIds()),
            default => $userRepository->findForBulkEmail(),
        };

        if (!$recipients) {
            $this->addFlash('error', 'No one currently matches this email\'s audience — nothing sent.');
            return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
        }

        /** @var User $sender */
        $sender = $this->getUser();

        $queued  = 0;
        $skipped = [];

        foreach ($recipients as $user) {
            if (!$user->getEmail()) {
                $skipped[] = $user;
                continue;
            }

            // Actual sending happens later, off-request, in SendBulkEmailMessageHandler — this
            // just hands each recipient to the bulk_email queue so a large audience can't blow
            // the request timeout. See config/packages/messenger.yaml for how that's consumed.
            $bus->dispatch(new SendBulkEmailMessage($email->getId(), $user->getId(), $sender->getId()));
            $queued++;
        }

        $email->markSent($sender);
        $email->setSentCount($queued);
        $em->flush();

        $this->addFlash('success', sprintf('Queued %d member%s to be emailed — delivery happens in the background.', $queued, $queued === 1 ? '' : 's'));
        if ($skipped) {
            $this->addFlash('error', sprintf(
                '%d member%s could not be emailed and %s skipped: %s.',
                count($skipped),
                count($skipped) === 1 ? '' : 's',
                count($skipped) === 1 ? 'was' : 'were',
                implode(', ', array_map(
                    static fn(User $u) => $u->getDisplayName() . ' (#' . $u->getId() . ')',
                    $skipped,
                )),
            ));
        }

        return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
    }

    /** Every past/draft email — admin only. */
    #[Route('/admin/settings/emails', name: 'app_admin_settings_emails')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(EmailRepository $emailRepository): Response
    {
        return $this->render('admin/settings/emails/index.html.twig', [
            'emails' => $emailRepository->findForList(),
        ]);
    }

    /** Admin-only: permanently removes a draft that was never sent — a draft can never have linked notes (those are only written by send()), but the check stays as a hard guard against ever deleting part of the audit trail. */
    #[Route('/admin/settings/emails/{id}/delete', name: 'app_admin_settings_email_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Email $email, NoteRepository $noteRepository, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_email_' . $email->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$email->isDraft() || $noteRepository->count(['email' => $email]) > 0) {
            $this->addFlash('error', 'Only a draft that has never been sent can be deleted.');
            return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
        }

        $em->remove($email);
        $em->flush();

        $this->addFlash('success', 'Draft deleted.');
        return $this->redirectToRoute('app_admin_settings_emails');
    }

    /** Admin-only: flags/unflags this email as reusable starting content for the compose screen. Independent of draft/sent status. */
    #[Route('/admin/settings/emails/{id}/toggle-template', name: 'app_admin_settings_email_toggle_template', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleTemplate(Request $request, Email $email, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle_template_' . $email->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $email->setTemplate(!$email->isTemplate());
        $em->flush();

        $this->addFlash('success', $email->isTemplate() ? 'Marked as a template.' : 'No longer a template.');
        return $this->redirectToRoute('app_admin_email_compose_edit', ['id' => $email->getId()]);
    }

    /**
     * What to show in the Audience section of the compose screen: a brand-new email reads it
     * from the query string (the "Email" buttons on other pages land here that way); an existing
     * one reads it back from whatever was actually saved, since the query string is long gone by
     * the time someone revisits it from the list.
     *
     * @return array{0: ?array, 1: ?array, 2: ?array} [eventAudience, certificationAudience, userAudience]
     */
    private function resolveDisplayAudience(
        Request $request,
        Email $email,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
        UserRepository $userRepository,
    ): array {
        if ($email->getId()) {
            $params = $email->getAudienceParams() ?? [];

            return match ($email->getAudienceType()) {
                Email::AUDIENCE_EVENT => [
                    $this->resolveEventAudience(
                        (int) ($params['eventId'] ?? 0),
                        (string) ($params['scope'] ?? 'date'),
                        (string) ($params['occurrenceDate'] ?? ''),
                        $eventRepository,
                        $attendeeRepository,
                    ),
                    null,
                    null,
                ],
                Email::AUDIENCE_CERTIFICATION => [
                    null,
                    $this->resolveCertificationAudience((int) ($params['certificationId'] ?? 0), $certificationRepository, $userCertificationRepository),
                    null,
                ],
                Email::AUDIENCE_USER => [
                    null,
                    null,
                    $this->resolveUserAudience((int) ($params['userId'] ?? 0), $userRepository),
                ],
                default => [null, null, null],
            };
        }

        $eventAudience = $this->resolveEventAudience(
            (int) $request->query->get('eventId', 0),
            (string) $request->query->get('scope', 'date'),
            (string) $request->query->get('occurrenceDate', ''),
            $eventRepository,
            $attendeeRepository,
        );
        $certificationAudience = $eventAudience ? null : $this->resolveCertificationAudience(
            (int) $request->query->get('certificationId', 0),
            $certificationRepository,
            $userCertificationRepository,
        );
        $userAudience = ($eventAudience || $certificationAudience) ? null : $this->resolveUserAudience(
            (int) $request->query->get('userId', 0),
            $userRepository,
        );

        return [$eventAudience, $certificationAudience, $userAudience];
    }

    /** @return array{0: string, 1: ?array, 2: string} [audienceType, audienceParams, audienceLabel] — what actually gets persisted on the Email row. */
    private function resolveAudienceMeta(
        ?array $eventAudience,
        ?array $certificationAudience,
        ?array $userAudience,
        array $tagIds,
        TagRepository $tagRepository,
        UserRepository $userRepository,
    ): array {
        return match (true) {
            (bool) $eventAudience => [
                Email::AUDIENCE_EVENT,
                [
                    'eventId'        => $eventAudience['event']->getId(),
                    'scope'          => $eventAudience['upcomingOnly'] ? 'series' : 'date',
                    'occurrenceDate' => $eventAudience['occurrenceDate']?->format('Y-m-d'),
                ],
                'Event: ' . $eventAudience['event']->getTitle()
                    . ($eventAudience['upcomingOnly'] ? ' — remaining series dates' : ($eventAudience['occurrenceDate'] ? ' — ' . $eventAudience['occurrenceDate']->format('d M Y') : ''))
                    . ' (' . count($eventAudience['recipients']) . ')',
            ],
            (bool) $certificationAudience => [
                Email::AUDIENCE_CERTIFICATION,
                ['certificationId' => $certificationAudience['certification']->getId()],
                'Certification: ' . $certificationAudience['certification']->getName() . ' (' . count($certificationAudience['recipients']) . ')',
            ],
            (bool) $userAudience => [
                Email::AUDIENCE_USER,
                ['userId' => $userAudience['user']->getId()],
                'Member: ' . ($userAudience['user']->getDisplayName() ?: $userAudience['user']->getEmail()),
            ],
            $tagIds !== [] => [
                Email::AUDIENCE_TAGS,
                ['tagIds' => $tagIds],
                $this->buildTagsAudienceLabel($tagIds, $tagRepository, $userRepository),
            ],
            default => [
                Email::AUDIENCE_ALL,
                null,
                sprintf('All opted-in members (%d)', count($userRepository->findForBulkEmail())),
            ],
        };
    }

    private function buildTagsAudienceLabel(array $tagIds, TagRepository $tagRepository, UserRepository $userRepository): string
    {
        $names = array_filter(array_map(static fn(int $id) => $tagRepository->find($id)?->getName(), $tagIds));

        return sprintf('Tags: %s (%d)', implode(', ', $names), count($userRepository->findForBulkEmail($tagIds)));
    }

    /**
     * Resolves the "email event attendees" audience, or null when this isn't an event-scoped
     * send (i.e. the general opted-in/tag-filtered audience applies).
     *
     * @return array{event: Event, upcomingOnly: bool, occurrenceDate: ?\DateTimeImmutable, recipients: User[]}|null
     */
    private function resolveEventAudience(
        int $eventId,
        string $scope,
        string $occurrenceDateRaw,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
    ): ?array {
        if (!$eventId) {
            return null;
        }

        $event = $eventRepository->find($eventId);
        if (!$event) {
            return null;
        }

        $upcomingOnly = $event->isRecurring() && $scope === 'series';
        $occurrenceDate = null;

        if (!$upcomingOnly && $occurrenceDateRaw !== '') {
            try {
                $occurrenceDate = new \DateTimeImmutable($occurrenceDateRaw);
            } catch (\Exception) {
                $occurrenceDate = null;
            }
        }

        return [
            'event'          => $event,
            'upcomingOnly'   => $upcomingOnly,
            'occurrenceDate' => $occurrenceDate,
            'recipients'     => $attendeeRepository->findAttendeeUsersForEvent($event, $occurrenceDate, $upcomingOnly),
        ];
    }

    /**
     * Resolves the "email certification holders" audience, or null when this isn't a
     * certification-scoped send.
     *
     * @return array{certification: Certification, recipients: User[]}|null
     */
    private function resolveCertificationAudience(
        int $certificationId,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
    ): ?array {
        if (!$certificationId) {
            return null;
        }

        $certification = $certificationRepository->find($certificationId);
        if (!$certification) {
            return null;
        }

        return [
            'certification' => $certification,
            'recipients'    => $userCertificationRepository->findHoldersForCertification($certification),
        ];
    }

    /**
     * Resolves the "email this one member" audience (landed on from the envelope icon next to a
     * member's email on their admin view page), or null when this isn't a single-member send.
     *
     * @return array{user: User, recipients: User[]}|null
     */
    private function resolveUserAudience(int $userId, UserRepository $userRepository): ?array
    {
        if (!$userId) {
            return null;
        }

        $user = $userRepository->find($userId);
        if (!$user instanceof User) {
            return null;
        }

        return [
            'user'       => $user,
            'recipients' => $user->getEmail() ? [$user] : [],
        ];
    }
}
