<?php

namespace App\Controller;

use App\Entity\MembershipType;
use App\Repository\MembershipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Manage membership types (name, description, price, duration, status, family flag) that members' individual memberships are taken out against. */
#[Route('/admin/settings/membership-types')]
#[IsGranted('ROLE_ADMIN')]
class AdminMembershipTypeController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_membership_types')]
    public function index(MembershipTypeRepository $membershipTypeRepository): Response
    {
        return $this->render('admin/settings/membership_types/index.html.twig', [
            'membershipTypes' => $membershipTypeRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_settings_membership_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, MembershipTypeRepository $membershipTypeRepository, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_membership_type_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $name  = trim($request->request->get('name', ''));
            $duration = $request->request->get('duration', '');

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($membershipTypeRepository->findOneBy(['name' => $name])) {
                $error = 'A membership type with that name already exists.';
            } elseif (!in_array($duration, [MembershipType::DURATION_DAY, MembershipType::DURATION_MONTH, MembershipType::DURATION_YEAR], true)) {
                $error = 'Choose a valid duration.';
            } else {
                $membershipType = new MembershipType();
                $membershipType->setName($name);
                $membershipType->setDescription(trim($request->request->get('description', '')) ?: null);
                $membershipType->setDuration($duration);
                $membershipType->setStatus($request->request->get('status') === MembershipType::STATUS_INACTIVE ? MembershipType::STATUS_INACTIVE : MembershipType::STATUS_ACTIVE);
                $membershipType->setIsFamily($request->request->has('isFamily'));

                $em->persist($membershipType);
                $em->flush();

                $this->addFlash('success', 'Membership type created.');
                return $this->redirectToRoute('app_admin_settings_membership_types');
            }
        }

        return $this->render('admin/settings/membership_types/new.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_settings_membership_type_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, MembershipType $membershipType, MembershipTypeRepository $membershipTypeRepository, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_membership_type_edit_' . $membershipType->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $name  = trim($request->request->get('name', ''));
            $duration = $request->request->get('duration', '');
            $duplicate = $membershipTypeRepository->findOneBy(['name' => $name]);

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($duplicate && $duplicate->getId() !== $membershipType->getId()) {
                $error = 'A membership type with that name already exists.';
            } elseif (!in_array($duration, [MembershipType::DURATION_DAY, MembershipType::DURATION_MONTH, MembershipType::DURATION_YEAR], true)) {
                $error = 'Choose a valid duration.';
            } else {
                $membershipType->setName($name);
                $membershipType->setDescription(trim($request->request->get('description', '')) ?: null);
                $membershipType->setDuration($duration);
                $membershipType->setStatus($request->request->get('status') === MembershipType::STATUS_INACTIVE ? MembershipType::STATUS_INACTIVE : MembershipType::STATUS_ACTIVE);
                $membershipType->setIsFamily($request->request->has('isFamily'));

                $em->flush();

                $this->addFlash('success', 'Membership type updated.');
                return $this->redirectToRoute('app_admin_settings_membership_type_edit', ['id' => $membershipType->getId()]);
            }
        }

        return $this->render('admin/settings/membership_types/edit.html.twig', [
            'membershipType' => $membershipType,
            'error'          => $error,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_settings_membership_type_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, MembershipType $membershipType, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_membership_type_delete_' . $membershipType->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$membershipType->getMemberships()->isEmpty()) {
            $this->addFlash('error', 'Cannot delete this membership type — members hold memberships against it. Mark it inactive instead.');
            return $this->redirectToRoute('app_admin_settings_membership_type_edit', ['id' => $membershipType->getId()]);
        }

        $em->remove($membershipType);
        $em->flush();

        $this->addFlash('success', 'Membership type deleted.');
        return $this->redirectToRoute('app_admin_settings_membership_types');
    }
}
