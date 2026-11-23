<?php

namespace App\Controller\Web;

use App\Entity\User;
use App\Service\UserService;
use App\Service\Mailer\WelcomeMailer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Self-service account creation — a real, member-chosen password and every field the account profile page has, so a new member never has to visit "My account" afterward just to fill in details they could have given up front. */
class RegistrationController extends AbstractController
{
    /**
     * A bot has been creating hundreds of accounts with exactly these details (phone 8 (999) 000-00-00,
     * name "Test User", company "Test Company"). Phone is compared as digits only so any formatting of it matches.
     */
    private const SPAM_PHONE_DIGITS = ['89990000000'];
    private const SPAM_FULL_NAMES   = ['test user'];
    private const SPAM_COMPANIES    = ['test company'];

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserService $userService,
        Security $security,
        WelcomeMailer $welcomeMailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_login_success');
        }

        $error           = null;
        $duplicateEmail  = false;
        $input = [
            'firstName'             => '',
            'lastName'              => '',
            'company'               => '',
            'email'                 => '',
            'phone'                 => '',
            'dateOfBirth'           => '',
            'emergencyContactName'  => '',
            'emergencyContactPhone' => '',
            'addressLine1'          => '',
            'addressLine2'          => '',
            'town'                  => '',
            'postcode'              => '',
            'optIn'                 => false,
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            foreach ($input as $field => $default) {
                if ($field === 'optIn') {
                    continue;
                }
                $input[$field] = trim($request->request->get($field, ''));
            }
            $input['optIn'] = $request->request->has('optIn');

            $email           = strtolower($input['email']);
            $password        = $request->request->get('password', '');
            $passwordConfirm = $request->request->get('passwordConfirm', '');

            if ($this->looksLikeSpam($input, $request)) {
                // Deliberately vague, so a bot gets nothing to adjust against. No account, no welcome email.
                $error = 'Sorry, we couldn\'t create your account. Please check your details, or contact us if this keeps happening.';
            } elseif ($input['firstName'] === '' || $input['lastName'] === '' || $email === '' || $password === '') {
                $error = 'Please fill in your name, email, and a password.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < UserService::MIN_PASSWORD_LENGTH) {
                $error = 'Your password must be at least ' . UserService::MIN_PASSWORD_LENGTH . ' characters.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Those passwords don\'t match.';
            } elseif ($userService->findExistingByEmail($email)) {
                $error          = 'An account already exists with that email address.';
                $duplicateEmail = true;
            }

            if (!$error) {
                $user = new User();
                $userService->applyProfileFields($user, $input);
                $user->setEmail($email);
                $user->setOptIn($input['optIn']);

                $userService->registerMember($user, $password, 'Contact added via self-registration.');
                $welcomeMailer->sendWelcome($user);

                $security->login($user);

                $this->addFlash('success', 'Welcome to Y Wal! Your account has been created.');
                return $this->redirectToRoute('app_login_success');
            }
        }

        return $this->render('security/register.html.twig', [
            'error'          => $error,
            'duplicateEmail' => $duplicateEmail,
            'input'          => $input,
        ]);
    }

    /**
     * Known bot sign-up details (see SPAM_* above), or the hidden "website" honeypot field filled in —
     * it's off-screen and skipped by keyboard and autofill, so only a form-filling bot ever puts anything there.
     */
    private function looksLikeSpam(array $input, Request $request): bool
    {
        if (trim($request->request->get('website', '')) !== '') {
            return true;
        }

        $phoneDigits = preg_replace('/\D+/', '', $input['phone']);
        $fullName    = strtolower(preg_replace('/\s+/', ' ', trim($input['firstName'] . ' ' . $input['lastName'])));
        $company     = strtolower(preg_replace('/\s+/', ' ', $input['company']));

        return in_array($phoneDigits, self::SPAM_PHONE_DIGITS, true)
            || in_array($fullName, self::SPAM_FULL_NAMES, true)
            || in_array($company, self::SPAM_COMPANIES, true);
    }
}
