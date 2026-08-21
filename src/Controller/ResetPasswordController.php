<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly string $mailerFrom,
        private readonly string $mailerFromName,
    ) {}

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, MailerInterface $mailer, UserRepository $userRepository): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot-password', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }
            return $this->processForgotPassword($request, $mailer, $userRepository);
        }

        return $this->render('reset_password/request.html.twig', [
            'error' => null,
        ]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        string $token,
    ): Response {
        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', 'The password reset link is invalid or has expired. Please request a new one.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset-password', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $password  = $request->request->get('password', '');
            $password2 = $request->request->get('password_confirm', '');

            if ($password !== $password2) {
                return $this->render('reset_password/reset.html.twig', [
                    'token' => $token,
                    'error' => 'Passwords do not match.',
                ]);
            }

            if (strlen($password) < 8) {
                return $this->render('reset_password/reset.html.twig', [
                    'token' => $token,
                    'error' => 'Password must be at least 8 characters.',
                ]);
            }

            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($hasher->hashPassword($user, $password));
            $em->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Your password has been updated. Please sign in.');
            return $this->redirectToRoute('app_login');
        }

        $this->storeTokenInSession($token);

        return $this->render('reset_password/reset.html.twig', [
            'token' => $this->getTokenFromSession(),
        ]);
    }

    private function processForgotPassword(Request $request, MailerInterface $mailer, UserRepository $userRepository): Response
    {
        $email = trim((string) $request->request->get('email', ''));
        $user  = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            return $this->redirectToRoute('app_check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            return $this->redirectToRoute('app_check_email');
        }

        $message = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to((string) $user->getEmail())
            ->subject('Password reset request — Y Wal')
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context(['resetToken' => $resetToken, 'user' => $user]);

        $mailer->send($message);

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_check_email');
    }
}
