<?php

namespace App\Controller\Web;

use App\Repository\EventRepository;
use App\Repository\NewsPostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * /sitemap.xml, as referenced from robots.txt — the public pages plus every published news post
 * and every published event that still has an occurrence to come. Events link to their shareable
 * landing page (app_event_landing), which is the URL that page declares as canonical. Pages behind
 * a login (including /shop), one-off flows (cart, password reset, unsubscribe) and noindex pages
 * (/routes) are left out.
 */
class SitemapController extends AbstractController
{
    private const STATIC_ROUTES = [
        'app_home',
        'app_events',
        'app_news',
        'app_donate',
        'app_sponsorship',
        'app_roles',
        'app_survey',
        'app_flyer',
        'app_policies',
        'app_policy_privacy',
        'app_policy_safeguarding',
        'app_policy_social_media',
        'app_policy_complaints',
    ];

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], format: 'xml')]
    public function index(EventRepository $eventRepository, NewsPostRepository $newsPostRepository): Response
    {
        $urls = [];

        foreach (self::STATIC_ROUTES as $route) {
            $urls[] = ['loc' => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null];
        }

        foreach ($newsPostRepository->findPublished() as $post) {
            $urls[] = [
                'loc'     => $this->generateUrl('app_news_show', ['slug' => $post->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => $post->getPublishedAt(),
            ];
        }

        foreach ($eventRepository->findPublishedUpcoming(new \DateTimeImmutable('today')) as $event) {
            $urls[] = [
                'loc'     => $this->generateUrl('app_event_landing', ['id' => $event->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => null,
            ];
        }

        $response = $this->render('sitemap/index.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
