<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserCertification;
use App\Repository\AttendeeRepository;
use App\Repository\UserRepository;
use App\Service\CertificationMailer;
use App\Service\CertificationPdfGenerator;
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

    /** A member's own certification record — full detail, including agreed declarations and signature once complete. */
    #[Route('/certifications/{recordId}', name: 'app_account_certification_view', requirements: ['recordId' => '\d+'], methods: ['GET'])]
    public function viewCertification(int $recordId, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $record = $this->findOwnCertificationRecord($em, $user, $recordId);

        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
        }

        return $this->render('account/certification_view.html.twig', [
            'record' => $record,
        ]);
    }

    /** Member self-service: work through declarations and sign to complete an in-progress certification. */
    #[Route('/certifications/{recordId}/complete', name: 'app_account_certification_complete', requirements: ['recordId' => '\d+'], methods: ['GET', 'POST'])]
    public function completeCertification(
        Request $request,
        int $recordId,
        EntityManagerInterface $em,
        CertificationPdfGenerator $pdfGenerator,
        CertificationMailer $certificationMailer,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $record = $this->findOwnCertificationRecord($em, $user, $recordId);

        if (!$record) {
            $this->addFlash('error', 'Certification record not found.');
            return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
        }

        if ($record->isComplete()) {
            return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
        }

        $declarations = $record->getCertification()->getDeclarations();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('complete_certification_' . $record->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
            }

            $agreedIds = array_map('intval', $request->request->all('declarations'));
            foreach ($declarations as $declaration) {
                if (!in_array($declaration->getId(), $agreedIds, true)) {
                    $error = 'Please agree to all of the declarations before completing this.';
                    break;
                }
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

                $pdf = $pdfGenerator->generate($record);
                $certificationMailer->sendCompletion($record, $pdf);

                $this->addFlash('success', $record->getCertification()->getName() . ' completed — thank you! A copy has been emailed to you.');
                return $this->redirectToRoute('app_account', ['_fragment' => 'certifications']);
            }
        }

        return $this->render('account/certification_complete.html.twig', [
            'record'       => $record,
            'declarations' => $declarations,
            'error'        => $error,
        ]);
    }

    private function findOwnCertificationRecord(EntityManagerInterface $em, User $user, int $recordId): ?UserCertification
    {
        $record = $em->getRepository(UserCertification::class)->find($recordId);

        return ($record && $record->getUser() === $user) ? $record : null;
    }
}
