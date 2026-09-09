<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserCertification;
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
                $user->setCompany(trim($request->request->get('company', '')) ?: null);
                $user->setEmail($newEmail);
                $user->setPhone(trim($request->request->get('phone', '')) ?: null);
                $user->setAddressLine1(trim($request->request->get('addressLine1', '')) ?: null);
                $user->setAddressLine2(trim($request->request->get('addressLine2', '')) ?: null);
                $user->setTown(trim($request->request->get('town', '')) ?: null);
                $user->setPostcode(trim($request->request->get('postcode', '')) ?: null);
                $user->setOptIn($request->request->has('optIn'));

                $dob = trim($request->request->get('dateOfBirth', ''));
                $user->setDateOfBirth($dob ? \DateTimeImmutable::createFromFormat('Y-m-d', $dob) ?: null : null);

                $user->setEmergencyContactName(trim($request->request->get('emergencyContactName', '')) ?: null);
                $user->setEmergencyContactPhone(trim($request->request->get('emergencyContactPhone', '')) ?: null);

                $em->flush();

                $this->addFlash('success', 'Your details have been updated.');
                return $this->redirect($this->resolveReturnTo($request));
            }
        }

        return $this->render('account/edit.html.twig', [
            'user'      => $user,
            'error'     => $error,
            'attendees' => $attendeeRepository->findAllForUser($user),
            'today'     => new \DateTimeImmutable('today'),
        ]);
    }

    /** A member's own (or one of their dependents') certification record — full detail, including agreed declarations and signature once complete. */
    #[Route('/certifications/{recordId}', name: 'app_account_certification_view', requirements: ['recordId' => '\d+'], methods: ['GET'])]
    public function viewCertification(int $recordId, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $record = $this->findAccessibleCertificationRecord($em, $user, $recordId);

        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
        }

        return $this->render('account/certification_view.html.twig', [
            'record' => $record,
        ]);
    }

    /**
     * Self-service: work through declarations and sign to complete an in-progress certification —
     * either the logged-in member's own, or one of their dependents' (who have no login of their
     * own, so a parent completes it on their behalf).
     */
    #[Route('/certifications/{recordId}/complete', name: 'app_account_certification_complete', requirements: ['recordId' => '\d+'], methods: ['GET', 'POST'])]
    public function completeCertification(
        Request $request,
        int $recordId,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $record = $this->findAccessibleCertificationRecord($em, $user, $recordId);

        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
        }

        if ($record->isSubmitted() || $record->isCancelled()) {
            return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
        }

        // The declarations are about the certificate holder, not necessarily the person completing
        // them — a parent filling this in for a dependent needs the dependent's own details on file.
        $holder = $record->getUser();

        $missingProfileFields = [];
        if (!$holder->getEmergencyContactName() || !$holder->getEmergencyContactPhone()) {
            $missingProfileFields[] = 'emergency contact name and phone number';
        }
        if (!$holder->getDateOfBirth()) {
            $missingProfileFields[] = 'date of birth';
        }
        if (!$holder->getPhone()) {
            $missingProfileFields[] = 'phone number';
        }
        if (!$holder->getAddressLine1() || !$holder->getTown() || !$holder->getPostcode()) {
            $missingProfileFields[] = 'address';
        }

        if ($missingProfileFields) {
            $message = $holder === $user
                ? 'Please add the following to your account before completing this certification: ' . implode(', ', $missingProfileFields) . '.'
                : 'Ask an admin to add the following to ' . $holder->getDisplayName() . "'s profile before completing this certification: " . implode(', ', $missingProfileFields) . '.';
            $this->addFlash('warning', $message);
        }

        $declarations = $record->getCertification()->getDeclarations();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('complete_certification_' . $record->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
            }

            if ($missingProfileFields) {
                return $this->redirectToRoute('app_account_certification_complete', ['recordId' => $record->getId()]);
            }

            $agreedIds = array_map('intval', $request->request->all('declarations'));
            foreach ($declarations as $declaration) {
                if (!in_array($declaration->getId(), $agreedIds, true)) {
                    $error = 'Please agree to all of the declarations before completing this.';
                    break;
                }
            }

            if (!$error && $request->request->get('signature_consent') !== '1') {
                $error = 'Please agree to the electronic signature declaration.';
            }

            $signature = trim($request->request->get('signature', ''));
            if (!$error && !str_starts_with($signature, 'data:image/png;base64,')) {
                $error = 'Please sign before completing this.';
            }

            if (!$error) {
                foreach ($declarations as $declaration) {
                    $record->addAgreedDeclaration($declaration);
                }
                $record->setSignature($signature);
                $record->setCompletedAt(new \DateTimeImmutable());
                $record->setCompletedBy($user);
                $em->flush();

                $this->addFlash('success', $record->getCertification()->getName() . ' submitted — thank you! It\'s now awaiting approval, and you\'ll be emailed a copy once it\'s signed off.');
                return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
            }
        }

        return $this->render('account/certification_complete.html.twig', [
            'record'               => $record,
            'declarations'         => $declarations,
            'error'                => $error,
            'missingProfileFields' => $missingProfileFields,
        ]);
    }

    /** A record belonging to $user themself, or to one of their dependents — dependents have no login of their own, so the parent acts on their behalf. */
    private function findAccessibleCertificationRecord(EntityManagerInterface $em, User $user, int $recordId): ?UserCertification
    {
        $record = $em->getRepository(UserCertification::class)->find($recordId);
        if (!$record) {
            return null;
        }

        $holder = $record->getUser();

        return ($holder === $user || $user->getDependents()->contains($holder)) ? $record : null;
    }

    /**
     * Where to send the member after saving their profile — normally back to the account page, but
     * the certification wizard's step 1 reuses this same form/endpoint and wants them back on the
     * wizard instead. Only ever a local path (never a full URL) so this can't become an open redirect.
     */
    private function resolveReturnTo(Request $request): string
    {
        $returnTo = $request->request->get('returnTo', '');

        return (is_string($returnTo) && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//'))
            ? $returnTo
            : $this->generateUrl('app_account');
    }
}
