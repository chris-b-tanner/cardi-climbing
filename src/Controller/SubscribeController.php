<?php

namespace App\Controller;

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

class SubscribeController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]     private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    #[Route('/subscribe', name: 'app_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        UserService $userService,
        EntityManagerInterface $em,
        MailerInterface $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('subscribe', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $email     = strtolower(trim($request->request->get('email', '')));
        $firstName = trim($request->request->get('firstName', ''));
        $lastName  = trim($request->request->get('lastName', ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('subscribe_error', 'Please enter a valid email address.');
            return $this->redirectToRoute('app_home');
        }

        $user = $userService->findExistingByEmail($email);

        $isNew = !$user;

        if ($isNew) {
            $user = $userService->createContact(
                email: $email,
                firstName: $firstName,
                lastName: $lastName,
                noteContent: 'Contact added via website subscription form.',
                optIn: true,
            );
        }

        if ($firstName) {
            $user->setFirstName($firstName);
        }
        if ($lastName) {
            $user->setLastName($lastName);
        }
        $user->setOptIn(true);

        $em->flush();

        if ($isNew) {
            $thanksEmail = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->mailerFromName))
                ->to($user->getEmail())
                ->subject('Thanks for signing up — Y Wal')
                ->htmlTemplate('email/subscribe_thanks.html.twig')
                ->textTemplate('email/subscribe_thanks.txt.twig')
                ->context(['user' => $user, 'recipientEmail' => $user->getEmail()]);

            $mailer->send($thanksEmail);
        }

        $this->addFlash('subscribe_success', 'Thanks for signing up — we\'ll keep you in the loop!');
        return $this->redirectToRoute('app_home');
    }
}
