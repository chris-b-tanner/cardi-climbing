<?php

namespace App\Controller;

use App\Repository\DoorLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Read-only report of device diagnostic/operational log entries — see door-access-firmware-spec.md § Diagnostic log. */
#[Route('/admin/settings/door-log')]
#[IsGranted('ROLE_ADMIN')]
class AdminDoorLogController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_door_log')]
    public function index(DoorLogRepository $doorLogRepository): Response
    {
        return $this->render('admin/settings/door_logs/index.html.twig', [
            'logs' => $doorLogRepository->findForReport(),
        ]);
    }
}
