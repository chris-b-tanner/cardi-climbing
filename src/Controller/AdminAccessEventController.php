<?php

namespace App\Controller;

use App\Repository\AccessEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Read-only report of door access activity — see door-access-spec.md § Access event log. */
#[Route('/admin/settings/access-log')]
#[IsGranted('ROLE_ADMIN')]
class AdminAccessEventController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_access_log')]
    public function index(AccessEventRepository $accessEventRepository): Response
    {
        return $this->render('admin/settings/access_events/index.html.twig', [
            'events' => $accessEventRepository->findForReport(),
        ]);
    }
}
