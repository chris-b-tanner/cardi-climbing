<?php

namespace App\Controller;

use App\Entity\Certification;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\CertificationRepository;
use App\Repository\EventRepository;
use App\Repository\TagRepository;
use App\Repository\UserCertificationRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[Route('/admin/email')]
#[IsGranted('ROLE_TEAM')]
class BulkEmailController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    #[Route('/compose', name: 'app_admin_email_compose', methods: ['GET'])]
    public function compose(
        Request $request,
        TagRepository $tagRepository,
        UserRepository $userRepository,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
    ): Response {
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

        return $this->render('admin/email/compose.html.twig', [
            'tags'                   => $tagRepository->findBy([], ['name' => 'ASC']),
            'totalOptedIn'           => count($userRepository->findForBulkEmail()),
            'eventAudience'          => $eventAudience,
            'certificationAudience'  => $certificationAudience,
        ]);
    }

    #[Route('/preview', name: 'app_admin_email_preview', methods: ['POST'])]
    public function preview(Request $request, Environment $twig): Response
    {
        $html = $twig->render('email/bulk.html.twig', [
            'subject' => $request->request->get('subject', '(No subject)'),
            'body'    => $request->request->get('body', ''),
        ]);

        return new Response($html);
    }

    #[Route('/count', name: 'app_admin_email_count', methods: ['POST'])]
    public function count(Request $request, UserRepository $userRepository): Response
    {
        $tagIds = array_map('intval', array_filter($request->request->all('tagIds')));
        $count  = count($userRepository->findForBulkEmail($tagIds));

        return new Response((string) $count);
    }

    #[Route('/send', name: 'app_admin_email_send', methods: ['POST'])]
    public function send(
        Request $request,
        UserRepository $userRepository,
        EventRepository $eventRepository,
        AttendeeRepository $attendeeRepository,
        CertificationRepository $certificationRepository,
        UserCertificationRepository $userCertificationRepository,
        MailerInterface $mailer,
    ): Response {
        $eventId           = (int) $request->request->get('eventId', 0);
        $scope             = (string) $request->request->get('scope', 'date');
        $occurrenceDateRaw = (string) $request->request->get('occurrenceDate', '');
        $certificationId   = (int) $request->request->get('certificationId', 0);

        if ($eventId) {
            $redirectParams = array_filter(['eventId' => $eventId, 'scope' => $scope, 'occurrenceDate' => $occurrenceDateRaw]);
        } elseif ($certificationId) {
            $redirectParams = ['certificationId' => $certificationId];
        } else {
            $redirectParams = [];
        }

        if (!$this->isCsrfTokenValid('bulk_email', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_admin_email_compose', $redirectParams);
        }

        $subject = trim($request->request->get('subject', ''));
        $body    = trim($request->request->get('body', ''));

        if (!$subject || !$body) {
            $this->addFlash('error', 'Subject and body are required.');
            return $this->redirectToRoute('app_admin_email_compose', $redirectParams);
        }

        $eventAudience = $this->resolveEventAudience($eventId, $scope, $occurrenceDateRaw, $eventRepository, $attendeeRepository);
        $certificationAudience = $eventAudience ? null : $this->resolveCertificationAudience($certificationId, $certificationRepository, $userCertificationRepository);

        if ($eventAudience) {
            $recipients = $eventAudience['recipients'];
        } elseif ($certificationAudience) {
            $recipients = $certificationAudience['recipients'];
        } else {
            $tagIds     = array_map('intval', array_filter($request->request->all('tagIds')));
            $recipients = $userRepository->findForBulkEmail($tagIds);
        }

        if (!$recipients) {
            $message = $eventAudience
                ? 'No members are booked onto that event/date.'
                : ($certificationAudience ? 'No members hold that certification.' : 'No opted-in members matched that audience.');
            $this->addFlash('error', $message);
            return $this->redirectToRoute('app_admin_email_compose', $redirectParams);
        }

        $sent = 0;

        foreach ($recipients as $user) {
            $context = [
                'subject' => $subject,
                'body'    => $body,
                'user'    => $user,
            ];

            // Event-attendee and certification-holder emails aren't a newsletter — no unsubscribe
            // footer (omitting recipientEmail suppresses it, same as the booking confirmation email).
            if (!$eventAudience && !$certificationAudience) {
                $context['recipientEmail'] = $user->getEmail();
            }

            $email = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->mailerFromName))
                ->to($user->getEmail())
                ->subject($subject)
                ->htmlTemplate('email/bulk.html.twig')
                ->textTemplate('email/bulk.txt.twig')
                ->context($context);

            $mailer->send($email);
            $sent++;
        }

        $this->addFlash('success', sprintf('Email sent to %d member%s.', $sent, $sent === 1 ? '' : 's'));
        return $this->redirectToRoute('app_admin_email_compose');
    }

    /**
     * Resolves the "email event attendees" audience from request params, or null when this
     * isn't an event-scoped send (i.e. the general opted-in/tag-filtered audience applies).
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
     * Resolves the "email certification holders" audience from request params, or null when
     * this isn't a certification-scoped send.
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
}
