<?php

namespace App\Controller;

use App\Entity\UserCertification;
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
        $query  = trim($request->query->get('q', ''));
        $status = $request->query->get('status', '');
        if (!in_array($status, [
            UserCertification::STATUS_IN_PROGRESS,
            UserCertification::STATUS_PENDING_APPROVAL,
            UserCertification::STATUS_COMPLETED,
            UserCertification::STATUS_CANCELLED,
        ], true)) {
            $status = '';
        }

        $records = $userCertificationRepository->search($query, $status);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/certifications/_list.html.twig', [
                'records' => $records,
            ]);
        }

        return $this->render('admin/certifications/index.html.twig', [
            'records'      => $records,
            'currentQuery'  => $query,
            'currentStatus' => $status,
        ]);
    }
}
