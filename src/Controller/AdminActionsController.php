<?php

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Service\NoteableResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Every pinned note across the system, in one place — the team's shared task list, since shifts change day to day and nothing should depend on one person's memory. */
#[Route('/admin/actions')]
#[IsGranted('ROLE_TEAM')]
class AdminActionsController extends AbstractController
{
    #[Route('', name: 'app_admin_actions')]
    public function index(NoteRepository $noteRepository, NoteableResolver $resolver): Response
    {
        $items = array_map(
            static fn ($note) => ['note' => $note, 'target' => $resolver->resolve($note)],
            $noteRepository->findAllPinned(),
        );

        return $this->render('admin/actions/index.html.twig', ['items' => $items]);
    }
}
