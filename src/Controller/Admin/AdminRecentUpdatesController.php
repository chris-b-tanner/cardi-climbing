<?php

namespace App\Controller\Admin;

use App\Repository\NoteRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Service\NoteableResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The latest notes across every record, newest first, as a feed of cards — searchable, and filterable by the tag/assignee of the person each note is about. See NoteRepository::findRecent(). */
#[Route('/admin/updates')]
#[IsGranted('ROLE_TEAM')]
class AdminRecentUpdatesController extends AbstractController
{
    private const LIMIT = 100;

    #[Route('', name: 'app_admin_recent_updates')]
    public function index(
        Request $request,
        NoteRepository $noteRepository,
        NoteableResolver $resolver,
        TagRepository $tagRepository,
        UserRepository $userRepository,
    ): Response {
        $query = trim($request->query->get('q', ''));
        $tagId = $request->query->get('tag', '') !== '' ? (int) $request->query->get('tag') : null;
        $assignedToRaw = $request->query->get('assignedTo', '');
        $assignedToId = match (true) {
            $assignedToRaw === '' => null,
            $assignedToRaw === 'unassigned' => 0,
            default => (int) $assignedToRaw,
        };
        $addedByRaw = $request->query->get('addedBy', '');
        $addedById = match (true) {
            $addedByRaw === '' => null,
            $addedByRaw === 'system' => 0,
            default => (int) $addedByRaw,
        };

        $items = array_map(
            static fn ($note) => ['note' => $note, 'target' => $resolver->resolve($note, withCompany: true)],
            $noteRepository->findRecent($query, $tagId, $assignedToId, self::LIMIT, $addedById),
        );

        $params = ['items' => $items, 'limit' => self::LIMIT];

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/recent_updates/_list.html.twig', $params);
        }

        return $this->render('admin/recent_updates/index.html.twig', $params + [
            'tags'                => $tagRepository->findBy([], ['name' => 'ASC']),
            'staff'               => $userRepository->findTeam('firstName'),
            'currentQuery'        => $query,
            'currentTagId'        => $tagId,
            'currentAssignedToId' => $assignedToId,
            'currentAddedById'    => $addedById,
        ]);
    }
}
