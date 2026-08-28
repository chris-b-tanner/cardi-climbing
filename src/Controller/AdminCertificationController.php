<?php

namespace App\Controller;

use App\Repository\UserCertificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/certifications')]
#[IsGranted('ROLE_TEAM')]
class AdminCertificationController extends AbstractController
{
    #[Route('', name: 'app_admin_certifications')]
    public function index(Request $request, UserCertificationRepository $userCertificationRepository): Response
    {
        $query = trim($request->query->get('q', ''));
        $records = $userCertificationRepository->search($query);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/certifications/_list.html.twig', [
                'records' => $records,
            ]);
        }

        return $this->render('admin/certifications/index.html.twig', [
            'records'      => $records,
            'currentQuery' => $query,
        ]);
    }
}
