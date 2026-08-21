<?php

namespace App\Controller;

use App\Entity\NewsPost;
use App\Entity\User;
use App\Repository\NewsPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/admin/news')]
#[IsGranted('ROLE_ADMIN')]
class AdminNewsController extends AbstractController
{
    #[Route('', name: 'app_admin_news')]
    public function index(NewsPostRepository $newsPostRepository): Response
    {
        return $this->render('admin/news/index.html.twig', [
            'posts' => $newsPostRepository->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_admin_news_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, NewsPostRepository $newsPostRepository): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_news_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $title = trim($request->request->get('title', ''));
            $body  = trim($request->request->get('body', ''));

            if ($title === '' || $body === '') {
                $error = 'Title and body are required.';
            } else {
                $slug = $this->resolveSlug($request->request->get('slug', ''), $title);

                if ($newsPostRepository->findOneBy(['slug' => $slug])) {
                    $error = 'That slug is already in use — please choose another.';
                } else {
                    /** @var User $admin */
                    $admin = $this->getUser();

                    $post = new NewsPost();
                    $post->setTitle($title);
                    $post->setSlug($slug);
                    $post->setBody($body);
                    $post->setAuthor($admin);
                    $post->setPublishedAt($request->request->has('published') ? new \DateTimeImmutable() : null);

                    $em->persist($post);
                    $em->flush();

                    $this->addFlash('success', 'News post created.');
                    return $this->redirectToRoute('app_admin_news');
                }
            }
        }

        return $this->render('admin/news/new.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_news_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        NewsPost $post,
        EntityManagerInterface $em,
        NewsPostRepository $newsPostRepository,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_news_edit_' . $post->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $title = trim($request->request->get('title', ''));
            $body  = trim($request->request->get('body', ''));

            if ($title === '' || $body === '') {
                $error = 'Title and body are required.';
            } else {
                $slug = $this->resolveSlug($request->request->get('slug', ''), $title);

                $existing = $newsPostRepository->findOneBy(['slug' => $slug]);
                if ($existing && $existing->getId() !== $post->getId()) {
                    $error = 'That slug is already in use — please choose another.';
                } else {
                    $post->setTitle($title);
                    $post->setSlug($slug);
                    $post->setBody($body);

                    $wantsPublished = $request->request->has('published');
                    if ($wantsPublished && !$post->getPublishedAt()) {
                        $post->setPublishedAt(new \DateTimeImmutable());
                    } elseif (!$wantsPublished) {
                        $post->setPublishedAt(null);
                    }

                    $em->flush();

                    $this->addFlash('success', 'News post updated.');
                    return $this->redirectToRoute('app_admin_news');
                }
            }
        }

        return $this->render('admin/news/edit.html.twig', [
            'post'  => $post,
            'error' => $error,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_news_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
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

    private function resolveSlug(string $requestedSlug, string $title): string
    {
        $slugger = new AsciiSlugger();
        $source  = trim($requestedSlug) !== '' ? $requestedSlug : $title;

        return strtolower((string) $slugger->slug($source));
    }
}
