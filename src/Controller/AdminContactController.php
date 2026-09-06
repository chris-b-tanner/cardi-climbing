<?php

namespace App\Controller;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Backs the generic "contact picker" modal (search + quick-create) used anywhere in admin that needs to pick or add a member. */
#[Route('/admin/contacts')]
#[IsGranted('ROLE_TEAM')]
class AdminContactController extends AbstractController
{
    /** Members for the picker — a member's dependents by default (if `dependentsOf` is given), or a general search once a query is typed. */
    #[Route('/search', name: 'app_admin_contact_search')]
    public function search(Request $request, UserRepository $userRepository): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            $dependentsOfId = (int) $request->query->get('dependentsOf', 0);
            $parent         = $dependentsOfId ? $userRepository->find($dependentsOfId) : null;
            $candidates     = $parent instanceof User ? $parent->getDependents()->toArray() : [];
        } elseif (mb_strlen($query) < 2) {
            $candidates = [];
        } else {
            $candidates = $userRepository->search($query, null, 20);
        }

        return $this->json(array_map(static function (User $candidate) {
            $displayName = trim(($candidate->getFirstName() ?? '') . ' ' . ($candidate->getLastName() ?? ''));
            $name        = $displayName ?: ($candidate->getEmail() ?: 'Member #' . $candidate->getId());

            return [
                'id'    => $candidate->getId(),
                'label' => $candidate->getEmail() ? $name . ' — ' . $candidate->getEmail() : $name,
            ];
        }, $candidates));
    }

    /** Quick-creates a member from the picker's mini contact form, optionally as another member's dependent. */
    #[Route('/new', name: 'app_admin_contact_new', methods: ['POST'])]
    public function new(Request $request, UserRepository $userRepository, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('contact_picker_create', $request->request->get('_csrf_token'))) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $firstName = trim($request->request->get('firstName', ''));
        $lastName  = trim($request->request->get('lastName', ''));
        $email     = trim($request->request->get('email', '')) ?: null;
        $dobRaw    = trim($request->request->get('dateOfBirth', ''));
        $dob       = $dobRaw !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $dobRaw) ?: null) : null;
        $parentId  = (int) $request->request->get('parentId', 0);

        if ($firstName === '') {
            return $this->json(['error' => 'First name is required.'], 422);
        }

        if ($email !== null && $userRepository->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'A member with that email address already exists.'], 422);
        }

        $contact = new User();
        $contact->setFirstName($firstName);
        $contact->setLastName($lastName ?: null);
        $contact->setEmail($email);
        $contact->setDateOfBirth($dob);
        // No login for this contact until they set a password via "forgot password" — requires an email on file.
        $contact->setPassword($hasher->hashPassword($contact, bin2hex(random_bytes(32))));

        if ($parentId) {
            $parent = $userRepository->find($parentId);
            if ($parent instanceof User) {
                $contact->setParent($parent);
            }
        }

        $em->persist($contact);

        /** @var User $admin */
        $admin     = $this->getUser();
        $adminName = trim(($admin->getFirstName() ?? '') . ' ' . ($admin->getLastName() ?? '')) ?: $admin->getEmail();

        $note = new Note();
        $note->setUser($contact);
        $note->setContent('Contact added manually by ' . $adminName . '.');
        $note->setAddedBy($admin);
        $em->persist($note);

        $em->flush();

        $displayName = trim($firstName . ' ' . $lastName);

        return $this->json([
            'id'    => $contact->getId(),
            'label' => $email ? $displayName . ' — ' . $email : $displayName,
        ]);
    }
}
