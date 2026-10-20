<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page for printed flyers and posters (the URL / QR code on them), so what's printed never
 * goes out of date: when the campaign changes, change this page's message — the flyer_* strings in
 * translations/messages.{en,cy}.yaml — and CTA_URL below, not the flyers.
 */
class FlyerController extends AbstractController
{
    /** Where the page's main button goes. Currently the Crowdfunder campaign, tagged so flyer traffic shows separately from the homepage banner's. */
    private const CTA_URL = 'https://www.crowdfunder.co.uk/p/cardigan-climbing-and-bouldering?utm_source=flyer&utm_medium=print&utm_campaign=cf1';

    #[Route('/flyer', name: 'app_flyer')]
    public function index(): Response
    {
        return $this->render('flyer/index.html.twig', [
            'ctaUrl' => self::CTA_URL,
        ]);
    }
}
