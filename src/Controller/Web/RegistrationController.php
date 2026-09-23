<?php

namespace App\Controller\Web;

use App\Entity\User;
use App\Service\UserService;
use App\Service\Mailer\WelcomeMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Self-service account creation — a real, member-chosen password and every field the account profile page has, so a new member never has to visit "My account" afterward just to fill in details they could have given up front. */
class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserService $userService,
        UserPasswordHasherInterface $passwordHasher,
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

            if ($input['firstName'] === '' || $input['lastName'] === '' || $email === '' || $password === '') {
                $error = 'Please fill in your name, email, and a password.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $error = 'Your password must be at least 8 characters.';
            } elseif ($password !== $passwordConfirm) {
                $error = 'Those passwords don\'t match.';
            } elseif ($userService->findExistingByEmail($email)) {
                $error          = 'An account already exists with that email address.';
                $duplicateEmail = true;
            }

            if (!$error) {
                $dob = $input['dateOfBirth'] !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $input['dateOfBirth']) ?: null) : null;

                $user = new User();
                $user->setEmail($email);
                $user->setFirstName($input['firstName']);
                $user->setLastName($input['lastName']);
                $user->setCompany($input['company'] ?: null);
                $user->setPhone($input['phone'] ?: null);
                $user->setDateOfBirth($dob);
                $user->setEmergencyContactName($input['emergencyContactName'] ?: null);
                $user->setEmergencyContactPhone($input['emergencyContactPhone'] ?: null);
                $user->setAddressLine1($input['addressLine1'] ?: null);
                $user->setAddressLine2($input['addressLine2'] ?: null);
                $user->setTown($input['town'] ?: null);
                $user->setPostcode($input['postcode'] ?: null);
                $user->setOptIn($input['optIn']);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setCreatedBy($user);

                $em->persist($user);
                $em->flush(); // assigns $user's id — needed before a Note can reference it via noteableId

                $userService->addNote($user, 'Contact added via self-registration.');
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
}
