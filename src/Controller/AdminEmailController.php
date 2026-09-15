<?php

namespace App\Controller;

use App\Entity\Email;
use App\Entity\User;
use App\Repository\EmailRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Draft → sent workflow for general/tag-filtered bulk emails — see BulkEmailController for the
 * existing compose-and-send-immediately tool, which this doesn't replace: fixed audiences (a
 * single member, an event's attendees, a certification's holders) stay on that tool untouched.
 * This is for broadcast-style sends that benefit from being saved and reviewed before they go
 * out. No separate approval step — a draft is edited freely and becomes sent the moment "Send"
 * is actually clicked. Open to any team member; targeting the whole membership (rather than a
 * tag) still requires ROLE_ADMIN, same restriction the existing compose tool already applies.
 */
#[Route('/admin/settings/emails')]
#[IsGranted('ROLE_TEAM')]
class AdminEmailController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    #[Route('', name: 'app_admin_settings_emails')]
    public function index(EmailRepository $emailRepository): Response
    {
        return $this->render('admin/settings/emails/index.html.twig', [
            'emails' => $emailRepository->findForList(),
        ]);
    }

    #[Route('/new', name: 'app_admin_settings_email_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        TagRepository $tagRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $email = new Email();

        if ($request->isMethod('POST')) {
            if ($response = $this->saveDraft($request, $email, $tagRepository, $userRepository, $em, isNew: true)) {
                return $response;
            }
        }

        return $this->render('admin/settings/emails/edit.html.twig', [
            'email' => $email,
            'tags'  => $tagRepository->findBy([], ['name' => 'ASC']),
            'totalOptedIn' => count($userRepository->findForBulkEmail()),
        ]);
    }

    /**
     * Also doubles as the "view" page for a sent email — the title link on the list page always
     * points here regardless of status. Only a draft can actually be edited/saved; a sent email
     * renders the same template read-only (see the template's own `email.draft` checks), since
     * there's otherwise nowhere for that link to go.
     */
    #[Route('/{id}/edit', name: 'app_admin_settings_email_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Email $email,
        TagRepository $tagRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$email->isDraft()) {
                $this->addFlash('error', 'Only a draft can be edited.');
                return $this->redirectToRoute('app_admin_settings_emails');
            }

            if ($response = $this->saveDraft($request, $email, $tagRepository, $userRepository, $em, isNew: false)) {
                return $response;
            }
        }

        return $this->render('admin/settings/emails/edit.html.twig', [
            'email' => $email,
            'tags'  => $tagRepository->findBy([], ['name' => 'ASC']),
            'totalOptedIn' => count($userRepository->findForBulkEmail()),
        ]);
    }

    /** Draft → sent — actually dispatches the email to its resolved audience. Open to any team member; the "all opted-in" audience is already restricted to admins at draft-save time (saveDraft()), so no extra gate needed here. */
    #[Route('/{id}/send', name: 'app_admin_settings_email_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(
        Request $request,
        Email $email,
        UserRepository $userRepository,
        MailerInterface $mailer,
        UserService $userService,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('send_email_' . $email->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$email->isDraft()) {
            $this->addFlash('error', 'This email has already been sent.');
            return $this->redirectToRoute('app_admin_settings_emails');
        }

        $recipients = $email->getAudienceType() === Email::AUDIENCE_TAGS
            ? $userRepository->findForBulkEmail($email->getTagIds())
            : $userRepository->findForBulkEmail();

        if (!$recipients) {
            $this->addFlash('error', 'No opted-in members matched this draft\'s audience — nothing sent.');
            return $this->redirectToRoute('app_admin_settings_emails');
        }

        $htmlTemplate = $email->isUseBlankLayout() ? 'email/bulk_blank.html.twig' : 'email/bulk.html.twig';
        $textTemplate = $email->isUseBlankLayout() ? 'email/bulk_blank.txt.twig' : 'email/bulk.txt.twig';

        /** @var User $sender */
        $sender = $this->getUser();

        $sent    = 0;
        $skipped = [];

        foreach ($recipients as $user) {
            if (!$user->getEmail()) {
                $skipped[] = $user;
                continue;
            }

            $message = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->mailerFromName))
                ->to($user->getEmail())
                ->subject($email->getSubject())
                ->htmlTemplate($htmlTemplate)
                ->textTemplate($textTemplate)
                ->context([
                    'subject'        => $email->getSubject(),
                    'body'           => $email->getBody(),
                    'user'           => $user,
                    // A saved draft is always the general/tag-filtered audience (never a fixed
                    // single-member/event/certification send), so it's always a newsletter-style
                    // send — always carries the unsubscribe footer, no exceptions to check for.
                    'recipientEmail' => $user->getEmail(),
                ]);

            try {
                $mailer->send($message);
                $sent++;
                $userService->addNote($user, 'Emailed: ' . $email->getSubject(), $sender, $email);
            } catch (\Throwable $e) {
                $skipped[] = $user;
                error_log('Draft email #' . $email->getId() . ' failed for user ' . $user->getId() . ': ' . $e->getMessage());
            }
        }

        $email->markSent($sender);
        $email->setSentCount($sent);
        $em->flush();

        $this->addFlash('success', sprintf('Email sent to %d member%s.', $sent, $sent === 1 ? '' : 's'));
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

        return $this->redirectToRoute('app_admin_settings_emails');
    }

    /** Shared save logic for new()/edit() — returns a redirect Response on success, or null to fall through and re-render the form (e.g. validation error). */
    private function saveDraft(
        Request $request,
        Email $email,
        TagRepository $tagRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        bool $isNew,
    ): ?Response {
        if (!$this->isCsrfTokenValid('email_draft', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $subject = trim($request->request->get('subject', ''));
        $body    = trim($request->request->get('body', ''));

        if (!$subject || !$body) {
            $this->addFlash('error', 'Subject and body are required.');
            return null;
        }

        $audienceType = $request->request->get('audienceType', Email::AUDIENCE_ALL) === Email::AUDIENCE_TAGS
            ? Email::AUDIENCE_TAGS
            : Email::AUDIENCE_ALL;
        $tagIds = array_map('intval', array_filter($request->request->all('tagIds')));

        if ($audienceType === Email::AUDIENCE_TAGS && !$tagIds) {
            $this->addFlash('error', 'Choose at least one tag, or switch to "All opted-in members".');
            return null;
        }

        // Same restriction as the existing compose-and-send tool: only an admin may target the
        // whole opted-in membership — everyone else has to narrow to a tag.
        if ($audienceType === Email::AUDIENCE_ALL && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'Only an admin can draft an email to the whole membership — choose a tag instead.');
            return null;
        }

        $email->setSubject($subject);
        $email->setBody($body);
        $email->setUseBlankLayout($request->request->getBoolean('useBlankLayout'));
        $email->setAudienceType($audienceType);
        $email->setAudienceParams($audienceType === Email::AUDIENCE_TAGS ? ['tagIds' => $tagIds] : null);
        $email->setAudienceLabel($this->buildAudienceLabel($audienceType, $tagIds, $tagRepository, $userRepository));
        $email->touch();

        if ($isNew) {
            /** @var User $author */
            $author = $this->getUser();
            $email->setCreatedBy($author);
            $em->persist($email);
        }

        $em->flush();

        $this->addFlash('success', $isNew ? 'Draft created.' : 'Draft updated.');
        return $this->redirectToRoute('app_admin_settings_emails');
    }

    private function buildAudienceLabel(string $audienceType, array $tagIds, TagRepository $tagRepository, UserRepository $userRepository): string
    {
        if ($audienceType === Email::AUDIENCE_ALL) {
            return sprintf('All opted-in members (%d)', count($userRepository->findForBulkEmail()));
        }

        $names = array_map(
            static fn($tag) => $tag->getName(),
            array_filter(array_map(static fn(int $id) => $tagRepository->find($id), $tagIds)),
        );

        return sprintf('Tags: %s (%d)', implode(', ', $names), count($userRepository->findForBulkEmail($tagIds)));
    }
}
