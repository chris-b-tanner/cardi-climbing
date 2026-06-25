<?php

namespace App\Controller;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
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
use Twig\Environment;

#[Route('/admin/email')]
#[IsGranted('ROLE_ADMIN')]
class BulkEmailController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    #[Route('/compose', name: 'app_admin_email_compose', methods: ['GET'])]
    public function compose(TagRepository $tagRepository, UserRepository $userRepository): Response
    {
        return $this->render('admin/email/compose.html.twig', [
            'tags'         => $tagRepository->findBy([], ['name' => 'ASC']),
            'totalOptedIn' => count($userRepository->findForBulkEmail()),
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
        EntityManagerInterface $em,
        MailerInterface $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('bulk_email', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_admin_email_compose');
        }

        $subject = trim($request->request->get('subject', ''));
        $body    = trim($request->request->get('body', ''));
        $tagIds  = array_map('intval', array_filter($request->request->all('tagIds')));

        if (!$subject || !$body) {
            $this->addFlash('error', 'Subject and body are required.');
            return $this->redirectToRoute('app_admin_email_compose');
        }

        $recipients = $userRepository->findForBulkEmail($tagIds);

        if (!$recipients) {
            $this->addFlash('error', 'No opted-in members matched that audience.');
            return $this->redirectToRoute('app_admin_email_compose');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $sent  = 0;

        foreach ($recipients as $user) {
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->mailerFromName))
                ->to($user->getEmail())
                ->subject($subject)
                ->htmlTemplate('email/bulk.html.twig')
                ->textTemplate('email/bulk.txt.twig')
                ->context([
                    'subject'        => $subject,
                    'body'           => $body,
                    'user'           => $user,
                    'recipientEmail' => $user->getEmail(),
                ]);

            $mailer->send($email);
            $sent++;

            $note = new Note();
            $note->setUser($user);
            $note->setContent('Bulk email sent: "' . $subject . '"');
            $note->setAddedBy($admin);
            $em->persist($note);
        }

        $em->flush();

        $this->addFlash('success', sprintf('Email sent to %d member%s.', $sent, $sent === 1 ? '' : 's'));
        return $this->redirectToRoute('app_admin_email_compose');
    }
}
