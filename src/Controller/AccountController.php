<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account')]
#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    #[Route('', name: 'app_account', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        AttendeeRepository $attendeeRepository,
    ): Response {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('account_edit', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $newEmail = strtolower(trim($request->request->get('email', '')));

            if ($newEmail !== $user->getEmail()) {
                $existing = $userRepository->findOneBy(['email' => $newEmail]);
                if ($existing && $existing->getId() !== $user->getId()) {
                    $error = 'That email address is already in use by another account.';
                }
            }

            if (!$error) {
                $user->setFirstName(trim($request->request->get('firstName', '')) ?: null);
                $user->setLastName(trim($request->request->get('lastName', '')) ?: null);
                $user->setEmail($newEmail);
                $user->setPhone(trim($request->request->get('phone', '')) ?: null);
                $user->setAddressLine1(trim($request->request->get('addressLine1', '')) ?: null);
                $user->setAddressLine2(trim($request->request->get('addressLine2', '')) ?: null);
                $user->setTown(trim($request->request->get('town', '')) ?: null);
                $user->setPostcode(trim($request->request->get('postcode', '')) ?: null);
                $user->setOptIn($request->request->has('optIn'));

                $em->flush();

                $this->addFlash('success', 'Your details have been updated.');
                return $this->redirectToRoute('app_account');
            }
        }

        return $this->render('account/edit.html.twig', [
            'user'      => $user,
            'error'     => $error,
            'attendees' => $attendeeRepository->findAllForUser($user),
            'today'     => new \DateTimeImmutable('today'),
        ]);
    }
}
