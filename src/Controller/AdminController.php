<?php

namespace App\Controller;

use App\Entity\Certification;
use App\Entity\Note;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Repository\AttendeeRepository;
use App\Repository\CertificationRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Service\CertificationMailer;
use App\Service\CertificationPdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_TEAM')]
class AdminController extends AbstractController
{
    #[Route('/users', name: 'app_admin_users')]
    public function users(Request $request, UserRepository $userRepository, TagRepository $tagRepository): Response
    {
        $query = trim($request->query->get('q', ''));
        $tagId = $request->query->get('tag') !== null && $request->query->get('tag') !== ''
            ? (int) $request->query->get('tag')
            : null;

        $users     = $userRepository->search($query, $tagId);
        $parentIds = $userRepository->findParentIds();

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/users/_list.html.twig', [
                'users'     => $users,
                'parentIds' => $parentIds,
            ]);
        }

        return $this->render('admin/users/index.html.twig', [
            'users'        => $users,
            'parentIds'    => $parentIds,
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

            $email = trim($request->request->get('email', '')) ?: null;

            if ($email !== null && $userRepository->findOneBy(['email' => $email])) {
                $error = 'A member with that email address already exists.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setFirstName(trim($request->request->get('firstName', '')) ?: null);
                $user->setLastName(trim($request->request->get('lastName', '')) ?: null);
                $user->setOptIn($request->request->has('optIn'));

                // No login for this contact until they set a password via "forgot password" — requires an email on file.
                $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(32))));

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
    public function showUser(User $user, UserRepository $userRepository, AttendeeRepository $attendeeRepository): Response
    {
        $duplicates = ($user->getFirstName() && $user->getLastName())
            ? $userRepository->findByFullName($user->getFirstName(), $user->getLastName(), $user->getId())
            : [];

        return $this->render('admin/users/show.html.twig', [
            'user'       => $user,
            'duplicates' => $duplicates,
            'bookings'   => $attendeeRepository->findAllForUser($user),
        ]);
    }

    #[Route('/users/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editUser(
        Request $request,
        User $user,
        EntityManagerInterface $em,
        TagRepository $tagRepository,
        UserRepository $userRepository,
    ): Response {
        $allTags = $tagRepository->findBy([], ['name' => 'ASC']);
        $canHaveDependents = $user->getParent() === null && $user->getEmail() !== null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_edit_' . $user->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $newEmail = trim($request->request->get('email', '')) ?: null;

            if ($newEmail === null && $user->hasDependents()) {
                $this->addFlash('error', 'Cannot remove this member\'s email address while they have dependents — remove their dependents first.');
                return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
            }

            $user->setFirstName(trim($request->request->get('firstName', '')) ?: null);
            $user->setLastName(trim($request->request->get('lastName', '')) ?: null);
            $user->setEmail($newEmail);
            $user->setEmail2(trim($request->request->get('email2', '')) ?: null);
            $user->setEmail3(trim($request->request->get('email3', '')) ?: null);
            $user->setMemo(trim($request->request->get('memo', '')) ?: null);
            $user->setOptIn($request->request->has('optIn'));
            $user->setPhone(trim($request->request->get('phone', '')) ?: null);

            $dob = trim($request->request->get('dateOfBirth', ''));
            $user->setDateOfBirth($dob ? \DateTimeImmutable::createFromFormat('Y-m-d', $dob) ?: null : null);

            $user->setEmergencyContactName(trim($request->request->get('emergencyContactName', '')) ?: null);
            $user->setEmergencyContactPhone(trim($request->request->get('emergencyContactPhone', '')) ?: null);
            $user->setAddressLine1(trim($request->request->get('addressLine1', '')) ?: null);
            $user->setAddressLine2(trim($request->request->get('addressLine2', '')) ?: null);
            $user->setTown(trim($request->request->get('town', '')) ?: null);
            $user->setPostcode(trim($request->request->get('postcode', '')) ?: null);

            if ($this->isGranted('ROLE_ADMIN')) {
                $role = $request->request->get('role', User::ROLE_MEMBER);
                if (in_array($role, [User::ROLE_ADMIN, User::ROLE_TEAM, User::ROLE_MEMBER], true)) {
                    if ($user === $this->getUser() && $role !== User::ROLE_ADMIN) {
                        $this->addFlash('error', 'You cannot change your own role.');
                        return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
                    }
                    $user->setRoles([$role]);
                }
            }

            $submittedTagIds = array_map('intval', $request->request->all('tags'));

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

            if ($canHaveDependents) {
                $submittedDependentIds = array_map('intval', $request->request->all('dependents'));

                foreach ($user->getDependents()->toArray() as $existingDependent) {
                    if (!in_array($existingDependent->getId(), $submittedDependentIds, true)) {
                        $existingDependent->setParent(null);
                    }
                }

                foreach ($submittedDependentIds as $dependentId) {
                    $candidate = $userRepository->find($dependentId);
                    if ($candidate && $candidate !== $user && !$candidate->hasDependents()) {
                        $candidate->setParent($user);
                    }
                }
            }

            $em->flush();

            $this->addFlash('success', 'Member updated.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/edit.html.twig', [
            'user'              => $user,
            'allTags'           => $allTags,
            'canHaveDependents' => $canHaveDependents,
        ]);
    }

    /** Search members eligible to be recorded as a dependent of {id} — excludes the member themselves and anyone who already has dependents of their own. */
    #[Route('/users/{id}/dependent-search', name: 'app_admin_user_dependent_search', requirements: ['id' => '\d+'])]
    public function dependentSearch(Request $request, User $user, UserRepository $userRepository): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if (mb_strlen($query) < 2 || !$user->getEmail()) {
            return $this->json([]);
        }

        $candidates = $userRepository->searchPotentialDependents($user, $query, 20);

        return $this->json(array_map(static function (User $candidate) {
            $displayName = trim(($candidate->getFirstName() ?? '') . ' ' . ($candidate->getLastName() ?? ''));
            $name        = $displayName ?: ($candidate->getEmail() ?: 'Member #' . $candidate->getId());

            return [
                'id'    => $candidate->getId(),
                'label' => $candidate->getEmail() ? $name . ' — ' . $candidate->getEmail() : $name,
            ];
        }, $candidates));
    }

    #[Route('/users/merge', name: 'app_admin_user_merge', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
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

        // Reassign notes, bookings, and certifications via raw SQL to bypass Doctrine cascade-remove
        $em->getConnection()->executeStatement(
            'UPDATE note SET user_id = :p WHERE user_id = :s',
            ['p' => $primaryId, 's' => $secondaryId]
        );
        $em->getConnection()->executeStatement(
            'UPDATE attendee SET user_id = :p WHERE user_id = :s',
            ['p' => $primaryId, 's' => $secondaryId]
        );
        $em->getConnection()->executeStatement(
            'UPDATE user_certification SET user_id = :p WHERE user_id = :s',
            ['p' => $primaryId, 's' => $secondaryId]
        );

        // Clear identity map so re-fetched secondary has empty notes/bookings/certifications collections
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
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUser(Request $request, User $user, EntityManagerInterface $em, AttendeeRepository $attendeeRepository): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if ($user->hasDependents()) {
            $this->addFlash('error', 'Cannot delete a member with dependents — remove their dependents first.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if ($attendeeRepository->findAllForUser($user) || !$user->getPayments()->isEmpty()) {
            $this->addFlash('error', 'Cannot delete a member with bookings or payments on record — archive them instead.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', 'Member deleted.');
        return $this->redirectToRoute('app_admin_users');
    }

    /**
     * "Delete" for a member with transactional history: scrubs personal details and marks
     * the record as archived, but keeps the row (and thus their bookings/payments) intact.
     */
    #[Route('/users/{id}/archive', name: 'app_admin_user_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function archiveUser(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('archive_user_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'You cannot archive your own account.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if ($user->isDeleted()) {
            $this->addFlash('error', 'This member has already been archived.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if ($user->hasDependents()) {
            $this->addFlash('error', 'Cannot archive a member with dependents — remove their dependents first.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $reason = trim($request->request->get('reason', ''));
        if ($reason === '') {
            $this->addFlash('error', 'Please give a reason for archiving this member.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $note = new Note();
        $note->setUser($user);
        $note->setContent('Member archived. Reason: ' . $reason);
        $note->setAddedBy($admin);
        $em->persist($note);

        $user->setFirstName('Deleted');
        $user->setLastName(null);
        $user->setEmail(null);
        $user->setEmail2(null);
        $user->setEmail3(null);
        $user->setAddressLine1(null);
        $user->setAddressLine2(null);
        $user->setTown(null);
        $user->setPostcode(null);
        $user->setEmergencyContactName(null);
        $user->setEmergencyContactPhone(null);
        $user->setDeletedAt(new \DateTimeImmutable());
        $user->setDeletedBy($admin);

        $em->flush();

        $this->addFlash('success', 'Member archived.');
        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
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

    /** Only the note's own author can delete it. */
    #[Route('/users/{id}/notes/{noteId}/delete', name: 'app_admin_user_delete_note', requirements: ['id' => '\d+', 'noteId' => '\d+'], methods: ['POST'])]
    public function deleteNote(Request $request, User $user, int $noteId, EntityManagerInterface $em): Response
    {
        $note = $em->getRepository(Note::class)->find($noteId);

        if (!$note || $note->getUser() !== $user) {
            $this->addFlash('error', 'Note not found.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if (!$this->isCsrfTokenValid('delete_note_' . $note->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($note->getAddedBy() !== $this->getUser()) {
            $this->addFlash('error', 'You can only delete notes you added.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $em->remove($note);
        $em->flush();

        $this->addFlash('success', 'Note deleted.');
        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
    }

    /** Picklist of certification types not already held by this member. Admin only — see confirmCertification(). */
    #[Route('/users/{id}/certifications/new', name: 'app_admin_user_certification_pick', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function pickCertification(User $user, CertificationRepository $certificationRepository): Response
    {
        $heldIds = array_map(
            static fn(UserCertification $record) => $record->getCertification()->getId(),
            array_filter(
                $user->getCertifications()->toArray(),
                static fn(UserCertification $record) => !$record->isCancelled(),
            ),
        );

        $available = array_values(array_filter(
            $certificationRepository->findBy([], ['name' => 'ASC']),
            static fn(Certification $certification) => !in_array($certification->getId(), $heldIds, true),
        ));

        return $this->render('admin/users/certifications/pick.html.twig', [
            'user'           => $user,
            'certifications' => $available,
        ]);
    }

    /** Confirm + assign a certification to a member. Admin only. */
    #[Route('/users/{id}/certifications/{certificationId}/confirm', name: 'app_admin_user_certification_confirm', requirements: ['id' => '\d+', 'certificationId' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function confirmCertification(
        Request $request,
        User $user,
        int $certificationId,
        CertificationRepository $certificationRepository,
        EntityManagerInterface $em,
        CertificationMailer $certificationMailer,
    ): Response {
        $certification = $certificationRepository->find($certificationId);

        if (!$certification) {
            $this->addFlash('error', 'Certification not found.');
            return $this->redirectToRoute('app_admin_user_certification_pick', ['id' => $user->getId()]);
        }

        if (!$user->getEmail()) {
            $this->addFlash('error', 'This member has no email address on file — add one before assigning a certification, since they need to be emailed a link to complete it.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $alreadyHeld = null;
        foreach ($user->getCertifications() as $record) {
            if ($record->getCertification() === $certification && !$record->isCancelled()) {
                $alreadyHeld = $record;
                break;
            }
        }

        if ($alreadyHeld) {
            $this->addFlash('error', 'This member already has that certification on record.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('add_certification_' . $user->getId() . '_' . $certification->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
            }

            /** @var User $admin */
            $admin = $this->getUser();

            $record = new UserCertification();
            $record->setUser($user);
            $record->setCertification($certification);
            $record->setStartedBy($admin);

            $em->persist($record);
            $em->flush();

            $certificationMailer->sendInvitation($record);

            $this->addFlash('success', $certification->getName() . ' added for ' . (trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail()) . ' — they\'ve been emailed a link to complete it.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/certifications/confirm.html.twig', [
            'user'          => $user,
            'certification' => $certification,
        ]);
    }

    /** View + complete a member's certification record. Admin only. */
    #[Route('/users/{id}/certifications/{recordId}/edit', name: 'app_admin_user_certification_edit', requirements: ['id' => '\d+', 'recordId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editCertification(User $user, int $recordId, EntityManagerInterface $em): Response
    {
        $record = $this->findCertificationRecord($em, $user, $recordId);
        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/certifications/edit.html.twig', [
            'user'   => $user,
            'record' => $record,
        ]);
    }

    /** Re-send the "complete your certification" email for a pending record. Admin only. */
    #[Route('/users/{id}/certifications/{recordId}/resend', name: 'app_admin_user_certification_resend', requirements: ['id' => '\d+', 'recordId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function resendCertificationInvite(Request $request, User $user, int $recordId, EntityManagerInterface $em, CertificationMailer $certificationMailer): Response
    {
        $record = $this->findCertificationRecord($em, $user, $recordId);
        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if (!$this->isCsrfTokenValid('resend_certification_' . $record->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($record->isCancelled()) {
            $this->addFlash('error', 'That certification has been cancelled.');
            return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
        }

        if ($record->isSubmitted()) {
            $this->addFlash('error', 'That certification has already been submitted.');
            return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
        }

        if (!$user->getEmail()) {
            $this->addFlash('error', 'This member has no email address on file — add one before resending the invitation.');
            return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
        }

        $certificationMailer->sendInvitation($record);

        $this->addFlash('success', 'Invitation email re-sent.');
        return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
    }

    /** Final sign-off — approve a member-submitted record, turning it into a held certification. Admin only. */
    #[Route('/users/{id}/certifications/{recordId}/approve', name: 'app_admin_user_certification_approve', requirements: ['id' => '\d+', 'recordId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approveCertification(
        Request $request,
        User $user,
        int $recordId,
        EntityManagerInterface $em,
        CertificationPdfGenerator $pdfGenerator,
        CertificationMailer $certificationMailer,
    ): Response {
        $record = $this->findCertificationRecord($em, $user, $recordId);
        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if (!$this->isCsrfTokenValid('approve_certification_' . $record->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$record->isSubmitted() || $record->isCancelled() || $record->isApproved()) {
            $this->addFlash('error', 'That certification cannot be approved.');
            return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $record->setApprovedAt(new \DateTimeImmutable());
        $record->setApprovedBy($admin);
        $em->flush();

        $message = 'Certification approved.';

        try {
            $pdf = $pdfGenerator->generate($record);
            $certificationMailer->sendCompletion($record, $pdf);
        } catch (\Throwable $e) {
            // The record is already saved as approved at this point — a PDF/email failure
            // shouldn't turn into a 500 and leave the admin thinking the approval didn't work.
            error_log('Certification completion email failed for record ' . $record->getId() . ': ' . $e->getMessage());
            $message = 'Certification approved. We had trouble emailing the member a copy; contact them if they need one.';
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
    }

    /** Hide/void a certification record — e.g. a bad actor or an expired certification. Admin only. */
    #[Route('/users/{id}/certifications/{recordId}/cancel', name: 'app_admin_user_certification_cancel', requirements: ['id' => '\d+', 'recordId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancelCertification(Request $request, User $user, int $recordId, EntityManagerInterface $em): Response
    {
        $record = $this->findCertificationRecord($em, $user, $recordId);
        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        if (!$this->isCsrfTokenValid('cancel_certification_' . $record->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($record->isCancelled()) {
            $this->addFlash('error', 'That certification is already cancelled.');
            return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $record->setCancelledAt(new \DateTimeImmutable());
        $record->setCancelledBy($admin);
        $em->flush();

        $this->addFlash('success', 'Certification cancelled.');
        return $this->redirectToRoute('app_admin_user_certification_edit', ['id' => $user->getId(), 'recordId' => $record->getId()]);
    }

    private function findCertificationRecord(EntityManagerInterface $em, User $user, int $recordId): ?UserCertification
    {
        $record = $em->getRepository(UserCertification::class)->find($recordId);

        return ($record && $record->getUser() === $user) ? $record : null;
    }

    /** Issue a (possibly partial) refund against a succeeded payment. Admin only. */
    #[Route('/payments/{id}/refund', name: 'app_admin_payment_refund', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refundPayment(Request $request, Payment $payment, EntityManagerInterface $em, StripeClient $stripe): Response
    {
        if (!$this->isCsrfTokenValid('refund_payment_' . $payment->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $redirect = $this->redirectToRoute('app_admin_user_show', ['id' => $payment->getUser()->getId()], Response::HTTP_SEE_OTHER);

        $remaining = (float) $payment->getRemainingRefundable();
        $amount    = (float) $request->request->get('amount', '');

        if ($amount <= 0 || $amount > $remaining) {
            $this->addFlash('error', sprintf('Enter a refund amount between £0.01 and £%.2f.', $remaining));
            return $redirect;
        }

        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $stripeRefund = $stripe->refunds->create([
                'payment_intent' => $payment->getStripePaymentIntentId(),
                'amount'         => (int) round($amount * 100),
            ]);
        } catch (ApiErrorException $e) {
            // Guards against a race between two concurrent refund requests both passing the
            // check above before either is saved — Stripe's own ledger is the final backstop.
            $this->addFlash('error', 'Stripe rejected this refund: ' . $e->getMessage());
            return $redirect;
        }

        $refund = new Refund();
        $refund->setPayment($payment);
        $refund->setAmount(number_format($amount, 2, '.', ''));
        $refund->setReason(trim($request->request->get('reason', '')) ?: null);
        $refund->setCreatedBy($admin);
        $refund->setStripeRefundId($stripeRefund->id);
        if ($stripeRefund->status === 'succeeded') {
            $refund->setSucceededAt(new \DateTimeImmutable());
        }

        $em->persist($refund);
        $em->flush();

        $this->addFlash('success', 'Refund of £' . number_format($amount, 2) . ' issued.');
        return $redirect;
    }
}
