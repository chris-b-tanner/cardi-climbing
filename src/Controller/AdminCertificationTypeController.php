<?php

namespace App\Controller;

use App\Entity\Certification;
use App\Repository\CertificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Manage certification types (name, description) — not to be confused with AdminCertificationController, which lists members' induction records. */
#[Route('/admin/settings/certifications')]
#[IsGranted('ROLE_ADMIN')]
class AdminCertificationTypeController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_certifications')]
    public function index(CertificationRepository $certificationRepository): Response
    {
        return $this->render('admin/settings/certifications/index.html.twig', [
            'certifications' => $certificationRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_settings_certification_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CertificationRepository $certificationRepository, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_certification_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $name = trim($request->request->get('name', ''));

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($certificationRepository->findOneBy(['name' => $name])) {
                $error = 'A certification with that name already exists.';
            } else {
                $certification = new Certification();
                $certification->setName($name);
                $certification->setDescription(trim($request->request->get('description', '')) ?: null);

                $em->persist($certification);
                $em->flush();

                $this->addFlash('success', 'Certification created.');
                return $this->redirectToRoute('app_admin_settings_certifications');
            }
        }

        return $this->render('admin/settings/certifications/new.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_settings_certification_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Certification $certification, CertificationRepository $certificationRepository, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_certification_edit_' . $certification->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $name = trim($request->request->get('name', ''));
            $duplicate = $certificationRepository->findOneBy(['name' => $name]);

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($duplicate && $duplicate->getId() !== $certification->getId()) {
                $error = 'A certification with that name already exists.';
            } else {
                $certification->setName($name);
                $certification->setDescription(trim($request->request->get('description', '')) ?: null);

                $em->flush();

                $this->addFlash('success', 'Certification updated.');
                return $this->redirectToRoute('app_admin_settings_certifications');
            }
        }

        return $this->render('admin/settings/certifications/edit.html.twig', [
            'certification' => $certification,
            'error'         => $error,
        ]);
    }
}
