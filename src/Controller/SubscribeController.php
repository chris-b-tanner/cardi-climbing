<?php

namespace App\Controller;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class SubscribeController extends AbstractController
{
    #[Route('/subscribe', name: 'app_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if (!$this->isCsrfTokenValid('subscribe', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $email     = strtolower(trim($request->request->get('email', '')));
        $firstName = trim($request->request->get('firstName', ''));
        $lastName  = trim($request->request->get('lastName', ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('subscribe_error', 'Please enter a valid email address.');
            return $this->redirectToRoute('app_home');
        }

        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(16))));
            $em->persist($user);

            $note = new Note();
            $note->setUser($user);
            $note->setContent('Contact added via website subscription form.');
            $em->persist($note);
        }

        if ($firstName) {
            $user->setFirstName($firstName);
        }
        if ($lastName) {
            $user->setLastName($lastName);
        }
        $user->setOptIn(true);

        $em->flush();

        $this->addFlash('subscribe_success', 'Thanks for signing up — we\'ll keep you in the loop!');
        return $this->redirectToRoute('app_home');
    }
}
