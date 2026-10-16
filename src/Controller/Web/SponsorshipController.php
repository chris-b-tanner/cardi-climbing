<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SponsorshipController extends AbstractController
{
    private const DOC_ID = '1XYcnT90BBG_xQFktUb1qPMc56pf8U-NFq8golqIMiak';

    #[Route('/sponsorship', name: 'app_sponsorship')]
    public function index(): Response
    {
        return $this->render('sponsorship/index.html.twig', [
            'docUrl' => 'https://docs.google.com/document/d/' . self::DOC_ID . '/edit?usp=sharing',
            'docEmbedUrl' => 'https://docs.google.com/document/d/' . self::DOC_ID . '/preview',
        ]);
    }
}
