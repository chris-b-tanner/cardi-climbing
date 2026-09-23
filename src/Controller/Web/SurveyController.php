<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /survey used to be a bare 301 redirect straight to the Google Form (see the now-removed
 * app_survey_redirect entry in config/routes.yaml) — now it's a real landing page so there's
 * somewhere to also offer the newsletter signup alongside the survey link.
 */
class SurveyController extends AbstractController
{
    private const GOOGLE_FORM_URL = 'https://forms.gle/c3YfdxHAJ7hfNz6Z9';

    #[Route('/survey', name: 'app_survey', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('survey/index.html.twig', [
            'googleFormUrl' => self::GOOGLE_FORM_URL,
        ]);
    }
}
