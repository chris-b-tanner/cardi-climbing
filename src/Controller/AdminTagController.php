<?php

namespace App\Controller;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Manage contact tags used to categorise members. */
#[Route('/admin/settings/tags')]
#[IsGranted('ROLE_ADMIN')]
class AdminTagController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_tags')]
    public function index(TagRepository $tagRepository): Response
    {
        return $this->render('admin/settings/tags/index.html.twig', [
            'tags' => $tagRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_settings_tag_new', methods: ['GET', 'POST'])]
    public function new(Request $request, TagRepository $tagRepository, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_tag_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $name = trim($request->request->get('name', ''));

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($tagRepository->findOneBy(['name' => $name])) {
                $error = 'A tag with that name already exists.';
            } else {
                $tag = new Tag();
                $tag->setName($name);

                $em->persist($tag);
                $em->flush();

                $this->addFlash('success', 'Tag created.');
                return $this->redirectToRoute('app_admin_settings_tags');
            }
        }

        return $this->render('admin/settings/tags/new.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_settings_tag_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Tag $tag, TagRepository $tagRepository, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_tag_edit_' . $tag->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $name      = trim($request->request->get('name', ''));
            $duplicate = $tagRepository->findOneBy(['name' => $name]);

            if ($name === '') {
                $error = 'Name is required.';
            } elseif ($duplicate && $duplicate->getId() !== $tag->getId()) {
                $error = 'A tag with that name already exists.';
            } else {
                $tag->setName($name);

                $em->flush();

                $this->addFlash('success', 'Tag updated.');
                return $this->redirectToRoute('app_admin_settings_tags');
            }
        }

        return $this->render('admin/settings/tags/edit.html.twig', [
            'tag'   => $tag,
            'error' => $error,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_settings_tag_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Tag $tag, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_tag_' . $tag->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        foreach ($tag->getUsers() as $user) {
            $user->removeTag($tag);
        }

        $name = $tag->getName();
        $em->remove($tag);
        $em->flush();

        $this->addFlash('success', 'Tag "' . $name . '" deleted and removed from all members.');

        return $this->redirectToRoute('app_admin_settings_tags');
    }
}
