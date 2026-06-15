<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('/users', name: 'app_admin_users')]
    public function users(Request $request, UserRepository $userRepository, TagRepository $tagRepository): Response
    {
        $query = trim($request->query->get('q', ''));
        $tagId = $request->query->get('tag') !== null && $request->query->get('tag') !== ''
            ? (int) $request->query->get('tag')
            : null;

        $users = $userRepository->search($query, $tagId);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/users/_list.html.twig', [
                'users' => $users,
            ]);
        }

        return $this->render('admin/users/index.html.twig', [
            'users'        => $users,
            'tags'         => $tagRepository->findBy([], ['name' => 'ASC']),
            'currentQuery' => $query,
            'currentTagId' => $tagId,
        ]);
    }

    #[Route('/users/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function newUser(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        UserRepository $userRepository,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_new', $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = trim($request->request->get('email', ''));

            if ($userRepository->findOneBy(['email' => $email])) {
                $error = 'A member with that email address already exists.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setFirstName(trim($request->request->get('firstName', '')) ?: null);
                $user->setLastName(trim($request->request->get('lastName', '')) ?: null);

                $role = $request->request->get('role', User::ROLE_MEMBER);
                if (in_array($role, [User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_MEMBER], true)) {
                    $user->setRoles([$role]);
                }

                $password = $request->request->get('password', '');
                $user->setPassword($hasher->hashPassword($user, $password));

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Member created.');
                return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
            }
        }

        return $this->render('admin/users/new.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/users/{id}', name: 'app_admin_user_show', requirements: ['id' => '\d+'])]
    public function showUser(User $user): Response
    {
        return $this->render('admin/users/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/users/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editUser(
        Request $request,
        User $user,
        EntityManagerInterface $em,
        TagRepository $tagRepository,
    ): Response {
        $allTags = $tagRepository->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_edit_' . $user->getId(), $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $user->setFirstName(trim($request->request->get('firstName', '')) ?: null);
            $user->setLastName(trim($request->request->get('lastName', '')) ?: null);
            $user->setEmail(trim($request->request->get('email', '')));

            $submittedTagIds = array_map('intval', $request->request->all()['tags'] ?? []);

            foreach ($user->getTags() as $tag) {
                if (!in_array($tag->getId(), $submittedTagIds, true)) {
                    $user->removeTag($tag);
                }
            }
            foreach ($allTags as $tag) {
                if (in_array($tag->getId(), $submittedTagIds, true) && !$user->hasTag($tag)) {
                    $user->addTag($tag);
                }
            }

            $em->flush();

            $this->addFlash('success', 'Member updated.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/edit.html.twig', [
            'user'    => $user,
            'allTags' => $allTags,
        ]);
    }
}
