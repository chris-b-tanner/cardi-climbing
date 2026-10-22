<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PolicyController extends AbstractController
{
    private const SAFEGUARDING_DOC_ID = '1SKVGw8GQCbvLjY8u9o8y723cbqBXpVG123Kk3rXp5Ns';
    private const SOCIAL_MEDIA_DOC_ID = '1Y644oDtfj2UGKHjA3Grkkjw0T6qCkmyAOda1SCgsc_8';

    #[Route('/policies', name: 'app_policies')]
    public function index(): Response
    {
        return $this->render('policy/index.html.twig');
    }

    #[Route('/policies/privacy', name: 'app_policy_privacy')]
    public function privacy(): Response
    {
        return $this->render('policy/privacy.html.twig');
    }

    #[Route('/policies/safeguarding', name: 'app_policy_safeguarding')]
    public function safeguarding(): Response
    {
        return $this->renderDoc('Safeguarding Policy', self::SAFEGUARDING_DOC_ID);
    }

    #[Route('/policies/social-media', name: 'app_policy_social_media')]
    public function socialMedia(): Response
    {
        return $this->renderDoc('Social Media Policy', self::SOCIAL_MEDIA_DOC_ID);
    }

    private function renderDoc(string $title, string $docId): Response
    {
        return $this->render('policy/doc.html.twig', [
            'title' => $title,
            'docUrl' => 'https://docs.google.com/document/d/' . $docId . '/edit?usp=sharing',
            'docEmbedUrl' => 'https://docs.google.com/document/d/' . $docId . '/preview',
        ]);
    }
}
