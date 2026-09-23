<?php

namespace App\Controller\Web;

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

/**
 * Add/edit lives on the public site itself, not the admin panel — the WYSIWYG editor renders
 * inside the exact same stylesheet as the published page (§ news/_editor.html.twig), so what an
 * editor sees while writing is what actually ships. An admin-panel-styled textarea could never
 * guarantee that: paragraph spacing, heading sizes etc. are defined in public/css/app.css, which
 * admin pages never load.
 *
 * The list and delete actions stay on AdminNewsController/`/admin/news` — this controller only
 * replaces the "new"/"edit" write actions, and always redirects back to that list on save.
 */
#[IsGranted('ROLE_TEAM')]
class NewsEditorController extends AbstractController
{
    #[Route('/news/new', name: 'app_news_new', methods: ['GET', 'POST'], priority: 10)]
    public function new(Request $request, EntityManagerInterface $em, NewsPostRepository $newsPostRepository): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('news_new', $request->request->get('_csrf_token'))) {
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
                    /** @var User $author */
                    $author = $this->getUser();

                    $post = new NewsPost();
                    $post->setTitle($title);
                    $post->setSlug($slug);
                    $post->setBody($body);
                    $post->setAuthor($author);
                    $post->setPublishedAt($request->request->has('published') ? new \DateTimeImmutable() : null);

                    $em->persist($post);
                    $em->flush();

                    $this->addFlash('success', 'News post created.');
                    return $this->redirectToRoute('app_admin_news');
                }
            }
        }

        return $this->render('news/_editor.html.twig', [
            'post'  => null,
            'error' => $error,
        ]);
    }

    #[Route('/news/{id}/edit', name: 'app_news_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'], priority: 10)]
    public function edit(
        Request $request,
        NewsPost $post,
        EntityManagerInterface $em,
        NewsPostRepository $newsPostRepository,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('news_edit_' . $post->getId(), $request->request->get('_csrf_token'))) {
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

        return $this->render('news/_editor.html.twig', [
            'post'  => $post,
            'error' => $error,
        ]);
    }

    private function resolveSlug(string $requestedSlug, string $title): string
    {
        $slugger = new AsciiSlugger();
        $source  = trim($requestedSlug) !== '' ? $requestedSlug : $title;

        return strtolower((string) $slugger->slug($source));
    }
}
