<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminPlaceholderController extends AbstractController
{
    private const SECTION_TITLES = [
        'calendar' => 'Calendar',
        'events'   => 'Events',
        'settings' => 'Settings',
    ];

    #[Route('/{section}', name: 'app_admin_placeholder', requirements: ['section' => 'calendar|events|settings'])]
    public function show(string $section): Response
    {
        return $this->render('admin/placeholder.html.twig', [
            'section' => $section,
            'title'   => self::SECTION_TITLES[$section],
        ]);
    }
}
