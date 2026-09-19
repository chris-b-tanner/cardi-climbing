<?php

namespace App\Controller;

use App\Entity\NewsPost;
use App\Repository\NewsPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The list and delete actions only — "new"/"edit" moved to NewsEditorController, on the public site itself so the WYSIWYG editor shares the live page's own stylesheet. */
#[Route('/admin/news')]
#[IsGranted('ROLE_TEAM')]
class AdminNewsController extends AbstractController
{
    #[Route('', name: 'app_admin_news')]
    public function index(NewsPostRepository $newsPostRepository): Response
    {
        return $this->render('admin/news/index.html.twig', [
            'posts' => $newsPostRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_news_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, NewsPost $post, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_news_' . $post->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $em->remove($post);
        $em->flush();

        $this->addFlash('success', 'News post deleted.');
        return $this->redirectToRoute('app_admin_news');
    }
}
