<?php

namespace App\Controller;

use App\Repository\NewsPostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NewsController extends AbstractController
{
    #[Route('/news', name: 'app_news')]
    public function index(NewsPostRepository $newsPostRepository): Response
    {
        return $this->render('news/index.html.twig', [
            'posts' => $newsPostRepository->findPublished(),
        ]);
    }

    #[Route('/news/{slug}', name: 'app_news_show')]
    public function show(string $slug, NewsPostRepository $newsPostRepository): Response
    {
        $post = $newsPostRepository->findOnePublishedBySlug($slug);

        if (!$post) {
            throw $this->createNotFoundException('News post not found.');
        }

        return $this->render('news/show.html.twig', [
            'post' => $post,
        ]);
    }
}
