<?php

namespace App\Controller;

use App\Entity\Note;
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
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
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

                /** @var User $admin */
                $admin = $this->getUser();
                $adminName = trim(($admin->getFirstName() ?? '') . ' ' . ($admin->getLastName() ?? '')) ?: $admin->getEmail();

                $note = new Note();
                $note->setUser($user);
                $note->setContent('Contact added manually by ' . $adminName . '.');
                $note->setAddedBy($admin);
                $em->persist($note);

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
    public function showUser(User $user, UserRepository $userRepository): Response
    {
        $duplicates = ($user->getFirstName() && $user->getLastName())
            ? $userRepository->findByFullName($user->getFirstName(), $user->getLastName(), $user->getId())
            : [];

        return $this->render('admin/users/show.html.twig', [
            'user'       => $user,
            'duplicates' => $duplicates,
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
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $user->setFirstName(trim($request->request->get('firstName', '')) ?: null);
            $user->setLastName(trim($request->request->get('lastName', '')) ?: null);
            $user->setEmail(trim($request->request->get('email', '')));
            $user->setEmail2(trim($request->request->get('email2', '')) ?: null);
            $user->setEmail3(trim($request->request->get('email3', '')) ?: null);
            $user->setOptIn($request->request->has('optIn'));
            $user->setPhone(trim($request->request->get('phone', '')) ?: null);
            $user->setAddressLine1(trim($request->request->get('addressLine1', '')) ?: null);
            $user->setAddressLine2(trim($request->request->get('addressLine2', '')) ?: null);
            $user->setTown(trim($request->request->get('town', '')) ?: null);
            $user->setPostcode(trim($request->request->get('postcode', '')) ?: null);

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

    #[Route('/users/merge', name: 'app_admin_user_merge', methods: ['GET', 'POST'])]
    public function mergeUsers(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('GET')) {
            $ids = array_map('intval', array_filter((array) $request->query->all('ids')));
            if (count($ids) !== 2) {
                $this->addFlash('error', 'Select exactly 2 members to merge.');
                return $this->redirectToRoute('app_admin_users');
            }
            $users = array_values(array_filter(array_map(fn($id) => $userRepository->find($id), $ids)));
            if (count($users) !== 2) {
                $this->addFlash('error', 'One or more members not found.');
                return $this->redirectToRoute('app_admin_users');
            }
            return $this->render('admin/users/merge.html.twig', ['users' => $users]);
        }

        if (!$this->isCsrfTokenValid('merge_users', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $primaryId   = (int) $request->request->get('primaryId');
        $secondaryId = (int) $request->request->get('secondaryId');

        if ($primaryId === $secondaryId) {
            $this->addFlash('error', 'Cannot merge a member with themselves.');
            return $this->redirectToRoute('app_admin_users');
        }

        $primary   = $userRepository->find($primaryId);
        $secondary = $userRepository->find($secondaryId);

        if (!$primary || !$secondary) {
            $this->addFlash('error', 'One or more members not found.');
            return $this->redirectToRoute('app_admin_users');
        }

        // Reassign notes via raw SQL to bypass Doctrine cascade-remove
        $em->getConnection()->executeStatement(
            'UPDATE note SET user_id = :p WHERE user_id = :s',
            ['p' => $primaryId, 's' => $secondaryId]
        );

        // Clear identity map so re-fetched secondary has an empty notes collection
        $em->clear();
        $primary   = $userRepository->find($primaryId);
        $secondary = $userRepository->find($secondaryId);

        // Transfer tags
        foreach ($secondary->getTags() as $tag) {
            $primary->addTag($tag);
        }

        // Fill blank fields on primary from secondary
        if (!$primary->getFirstName() && $secondary->getFirstName())   $primary->setFirstName($secondary->getFirstName());
        if (!$primary->getLastName()  && $secondary->getLastName())    $primary->setLastName($secondary->getLastName());
        if (!$primary->getPhone()     && $secondary->getPhone())       $primary->setPhone($secondary->getPhone());
        if (!$primary->getAddressLine1() && $secondary->getAddressLine1()) $primary->setAddressLine1($secondary->getAddressLine1());
        if (!$primary->getAddressLine2() && $secondary->getAddressLine2()) $primary->setAddressLine2($secondary->getAddressLine2());
        if (!$primary->getTown()      && $secondary->getTown())        $primary->setTown($secondary->getTown());
        if (!$primary->getPostcode()  && $secondary->getPostcode())    $primary->setPostcode($secondary->getPostcode());
        if ($secondary->isOptIn()) $primary->setOptIn(true);

        // Collect all unique emails from secondary not already on primary
        $existingEmails = array_filter([$primary->getEmail(), $primary->getEmail2(), $primary->getEmail3()]);
        $overflow = [];
        foreach (array_filter([$secondary->getEmail(), $secondary->getEmail2(), $secondary->getEmail3()]) as $alt) {
            if (!in_array($alt, $existingEmails, true)) {
                if (!$primary->addAlternateEmail($alt)) {
                    $overflow[] = $alt;
                }
                $existingEmails[] = $alt;
            }
        }

        $em->remove($secondary);
        $em->flush();

        if ($overflow) {
            $this->addFlash('error', 'Merge complete, but could not store all alternate emails (slots full): ' . implode(', ', $overflow));
        } else {
            $this->addFlash('success', 'Members merged successfully.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['id' => $primary->getId()]);
    }

    #[Route('/users/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteUser(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', 'Member deleted.');
        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/users/{id}/notes', name: 'app_admin_user_add_note', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addNote(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('note_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $content = trim($request->request->get('content', ''));

        if ($content !== '') {
            /** @var User $admin */
            $admin = $this->getUser();

            $note = new Note();
            $note->setUser($user);
            $note->setContent($content);
            $note->setAddedBy($admin);

            $em->persist($note);
            $em->flush();
        }

        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
    }
}
