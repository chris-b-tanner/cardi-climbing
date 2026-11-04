<?php

namespace App\Controller\Web;

use App\Controller\Concern\SafeLocalRedirectTrait;
use App\Service\Mailer\WelcomeMailer;
use App\Service\SubscribeGuard;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SubscribeController extends AbstractController
{
    use SafeLocalRedirectTrait;

    private const SUCCESS_MESSAGE = 'Thanks for signing up — we\'ll keep you in the loop!';

    #[Route('/subscribe', name: 'app_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        UserService $userService,
        EntityManagerInterface $em,
        WelcomeMailer $welcomeMailer,
        SubscribeGuard $subscribeGuard,
    ): Response {
        if (!$this->isCsrfTokenValid('subscribe', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        // Honeypot, time trap and per-IP limit — see SubscribeGuard. A bot gets the normal thank-you
        // so it has nothing to adapt to; nothing is saved and no welcome email goes out.
        $guard = $subscribeGuard->check($request);
        if ($guard === SubscribeGuard::BOT) {
            $this->addFlash('subscribe_success', self::SUCCESS_MESSAGE);
            return $this->redirect($this->resolveReturnTo($request));
        }
        if ($guard === SubscribeGuard::RATE_LIMITED) {
            $this->addFlash('subscribe_error', 'Too many signups from your connection just now. Please try again in an hour.');
            return $this->redirect($this->resolveReturnTo($request));
        }

        $email     = strtolower(trim($request->request->get('email', '')));
        $firstName = trim($request->request->get('firstName', ''));
        $lastName  = trim($request->request->get('lastName', ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('subscribe_error', 'Please enter a valid email address.');
            return $this->redirect($this->resolveReturnTo($request));
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

        // Only fill in a missing name. Anyone can submit anyone's address here, so this public
        // form must never overwrite the name on an existing record.
        if ($firstName && !$user->getFirstName()) {
            $user->setFirstName($firstName);
        }
        if ($lastName && !$user->getLastName()) {
            $user->setLastName($lastName);
        }
        $user->setOptIn(true);

        $em->flush();

        if ($isNew) {
            $welcomeMailer->sendSubscribeThanks($user);
        }

        $this->addFlash('subscribe_success', self::SUCCESS_MESSAGE);
        return $this->redirect($this->resolveReturnTo($request));
    }

    /** Defaults to the homepage so the two existing forms there (which never send a returnTo) keep working unchanged. */
    private function resolveReturnTo(Request $request): string
    {
        return $this->localPathOr($request->request->get('returnTo', ''), $this->generateUrl('app_home'));
    }
}
