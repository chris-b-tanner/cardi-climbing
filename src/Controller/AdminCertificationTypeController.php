<?php

namespace App\Controller;

use App\Entity\Certification;
use App\Entity\Declaration;
use App\Repository\CertificationRepository;
use App\Repository\DeclarationRepository;
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
                return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
            }
        }

        return $this->render('admin/settings/certifications/edit.html.twig', [
            'certification' => $certification,
            'error'         => $error,
        ]);
    }

    /** Add a declaration to this certification's form. */
    #[Route('/{id}/declarations', name: 'app_admin_settings_certification_declaration_new', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function newDeclaration(Request $request, Certification $certification, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_declaration_new_' . $certification->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $text = trim($request->request->get('text', ''));
        if ($text === '') {
            $this->addFlash('error', 'Declaration text is required.');
            return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
        }

        $nextSortOrder = 0;
        foreach ($certification->getDeclarations() as $existing) {
            $nextSortOrder = max($nextSortOrder, $existing->getSortOrder() + 1);
        }

        $declaration = new Declaration();
        $declaration->setCertification($certification);
        $declaration->setText($text);
        $declaration->setSortOrder($nextSortOrder);

        $em->persist($declaration);
        $em->flush();

        $this->addFlash('success', 'Declaration added.');
        return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
    }

    /** Edit a declaration's wording. */
    #[Route('/{id}/declarations/{declarationId}/edit', name: 'app_admin_settings_certification_declaration_edit', requirements: ['id' => '\d+', 'declarationId' => '\d+'], methods: ['POST'])]
    public function editDeclaration(Request $request, Certification $certification, int $declarationId, EntityManagerInterface $em): Response
    {
        $declaration = $this->findDeclaration($em, $certification, $declarationId);
        if (!$declaration) {
            $this->addFlash('error', 'Declaration not found.');
            return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
        }

        if (!$this->isCsrfTokenValid('admin_declaration_edit_' . $declaration->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $text = trim($request->request->get('text', ''));
        if ($text === '') {
            $this->addFlash('error', 'Declaration text is required.');
            return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
        }

        $declaration->setText($text);
        $em->flush();

        $this->addFlash('success', 'Declaration updated.');
        return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
    }

    /**
     * Remove a declaration from this certification's form. Hard-deletes it, unless a member has
     * already agreed to it on a completed certification — in that case it's detached (kept on
     * record, unlinked from the certification) so the historical record keeps its wording.
     */
    #[Route('/{id}/declarations/{declarationId}/delete', name: 'app_admin_settings_certification_declaration_delete', requirements: ['id' => '\d+', 'declarationId' => '\d+'], methods: ['POST'])]
    public function deleteDeclaration(
        Request $request,
        Certification $certification,
        int $declarationId,
        EntityManagerInterface $em,
        DeclarationRepository $declarationRepository,
    ): Response {
        $declaration = $this->findDeclaration($em, $certification, $declarationId);
        if (!$declaration) {
            $this->addFlash('error', 'Declaration not found.');
            return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
        }

        if (!$this->isCsrfTokenValid('admin_declaration_delete_' . $declaration->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($declarationRepository->isInUse($declaration)) {
            $declaration->setCertification(null);
            $em->flush();
            $this->addFlash('success', 'Declaration removed. It had already been agreed to on a completed certification, so the record was kept but detached rather than deleted.');
        } else {
            $em->remove($declaration);
            $em->flush();
            $this->addFlash('success', 'Declaration deleted.');
        }

        return $this->redirectToRoute('app_admin_settings_certification_edit', ['id' => $certification->getId()]);
    }

    private function findDeclaration(EntityManagerInterface $em, Certification $certification, int $declarationId): ?Declaration
    {
        $declaration = $em->getRepository(Declaration::class)->find($declarationId);

        return ($declaration && $declaration->getCertification() === $certification) ? $declaration : null;
    }
}
