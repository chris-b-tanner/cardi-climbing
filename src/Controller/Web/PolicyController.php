<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PolicyController extends AbstractController
{
    private const SAFEGUARDING_DOC_ID = '1SKVGw8GQCbvLjY8u9o8y723cbqBXpVG123Kk3rXp5Ns';
    private const SOCIAL_MEDIA_DOC_ID = '1Y644oDtfj2UGKHjA3Grkkjw0T6qCkmyAOda1SCgsc_8';
    private const COMPLAINTS_DOC_ID = '1BN5w6oJ0nzU-rGOcDDoOLYscNpqEb1nhxGpy6CTEUZs';
    private const ENVIRONMENTAL_DOC_ID = '1ByHQ8CdTn7LTlWi7QI1wmW5VS1iWU1GfF3wgNKNRk_M';
    private const BILINGUAL_DOC_ID = '139ihe7qKFqkDGdJQId3RUsW2oZ4zgs3Ii81v-GWJD-8';
    private const GOVERNANCE_DOC_ID = '1DD217uCVc4xP7aqL62zgv_u8DneZVCg-uNg4MDbo9W8';
    private const VOLUNTEER_DOC_ID = '1kN2ag3Ay73PE8XWqotsMtswjULvVXwF8JmtUFzVOS6I';

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

    #[Route('/policies/complaints-and-whistleblowing', name: 'app_policy_complaints')]
    public function complaints(): Response
    {
        return $this->renderDoc('Complaints and Whistleblowing Policy', self::COMPLAINTS_DOC_ID);
    }

    #[Route('/policies/environmental', name: 'app_policy_environmental')]
    public function environmental(): Response
    {
        return $this->renderDoc('Environmental Policy', self::ENVIRONMENTAL_DOC_ID);
    }

    #[Route('/policies/bilingual-service-and-communications', name: 'app_policy_bilingual')]
    public function bilingual(): Response
    {
        return $this->renderDoc('Bilingual Service and Communications Policy', self::BILINGUAL_DOC_ID);
    }

    #[Route('/policies/governance', name: 'app_policy_governance')]
    public function governance(): Response
    {
        return $this->renderDoc('Governance Statement', self::GOVERNANCE_DOC_ID);
    }

    #[Route('/policies/volunteer-engagement', name: 'app_policy_volunteer')]
    public function volunteer(): Response
    {
        return $this->renderDoc('Volunteer Engagement Guidelines', self::VOLUNTEER_DOC_ID);
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
