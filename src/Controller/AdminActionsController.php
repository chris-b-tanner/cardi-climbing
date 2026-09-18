<?php

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Repository\UserRepository;
use App\Service\NoteableResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Every pinned note across the system, in one place — the team's shared task list, since shifts change day to day and nothing should depend on one person's memory. */
#[Route('/admin/actions')]
#[IsGranted('ROLE_TEAM')]
class AdminActionsController extends AbstractController
{
    #[Route('', name: 'app_admin_actions')]
    public function index(NoteRepository $noteRepository, NoteableResolver $resolver, UserRepository $userRepository): Response
    {
        $items = array_map(
            static fn ($note) => ['note' => $note, 'target' => $resolver->resolve($note)],
            $noteRepository->findAllPinned(),
        );

        return $this->render('admin/actions/index.html.twig', [
            'items' => $items,
            'staff' => $userRepository->findTeam(),
        ]);
    }

    /** Backs the Actions page's content search box — every pinned note is already in the page, so this just returns which ones (by id) match, for the client to filter by. */
    #[Route('/search', name: 'app_admin_actions_search')]
    public function search(Request $request, NoteRepository $noteRepository): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            return $this->json(['ids' => null]);
        }

        return $this->json(['ids' => $noteRepository->searchPinnedIds($query)]);
    }
}
