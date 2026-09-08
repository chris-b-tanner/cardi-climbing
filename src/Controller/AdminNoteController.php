<?php

namespace App\Controller;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\NoteRepository;
use App\Repository\ProductRepository;
use App\Repository\SalesOrderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Generic add/pin/delete for Notes attached to any of the five noteable types — shared by the
 * Member, Attendee, Event, Product, and Order admin pages rather than one set of actions per type.
 */
#[Route('/admin/notes')]
#[IsGranted('ROLE_TEAM')]
class AdminNoteController extends AbstractController
{
    private const TYPES = [Note::TYPE_MEMBER, Note::TYPE_ATTENDEE, Note::TYPE_EVENT, Note::TYPE_PRODUCT, Note::TYPE_ORDER];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly EventRepository $eventRepository,
        private readonly ProductRepository $productRepository,
        private readonly SalesOrderRepository $salesOrderRepository,
    ) {}

    #[Route('/{noteableType}/{noteableId}', name: 'app_admin_note_add', requirements: ['noteableId' => '\d+'], methods: ['POST'])]
    public function add(Request $request, string $noteableType, int $noteableId, EntityManagerInterface $em): Response
    {
        if (!in_array($noteableType, self::TYPES, true)) {
            throw $this->createNotFoundException('Unknown note type.');
        }

        $noteable = $this->findNoteable($noteableType, $noteableId);
        if (!$noteable) {
            throw $this->createNotFoundException('Record not found.');
        }

        if (!$this->isCsrfTokenValid('note_add_' . $noteableType . '_' . $noteableId, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $content = trim($request->request->get('content', ''));

        if ($content !== '') {
            /** @var User $admin */
            $admin = $this->getUser();

            $note = new Note();
            $note->setNoteable($noteable);
            $note->setContent($content);
            $note->setAddedBy($admin);

            $em->persist($note);
            $em->flush();
        }

        return $this->redirectForNoteable($noteableType, $noteableId);
    }

    /** Only the note's own author can delete it. */
    #[Route('/{id}/delete', name: 'app_admin_note_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Note $note, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('note_delete_' . $note->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if ($note->getAddedBy() !== $this->getUser()) {
            $this->addFlash('error', 'You can only delete notes you added.');
            return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
        }

        $type = $note->getNoteableType();
        $id   = $note->getNoteableId();

        $em->remove($note);
        $em->flush();

        $this->addFlash('success', 'Note deleted.');
        return $this->redirectForNoteable($type, $id);
    }

    #[Route('/{id}/pin', name: 'app_admin_note_pin', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pin(Request $request, Note $note, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('note_pin_' . $note->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        /** @var User $admin */
        $admin = $this->getUser();

        if ($note->isPinned()) {
            $note->unpin();
            $this->addFlash('success', 'Note unpinned.');
        } else {
            $note->pin($admin);
            $this->addFlash('success', 'Note pinned.');
        }

        $em->flush();

        return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
    }

    /** Resolves a pinned note — distinct from unpin: keeps a record of who completed it and when. */
    #[Route('/{id}/complete', name: 'app_admin_note_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(Request $request, Note $note, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('note_complete_' . $note->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $note->complete($admin);
        $em->flush();

        $this->addFlash('success', 'Note completed.');
        return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
    }

    private function findNoteable(string $type, int $id): ?object
    {
        return match ($type) {
            Note::TYPE_MEMBER => $this->userRepository->find($id),
            Note::TYPE_ATTENDEE => $this->attendeeRepository->find($id),
            Note::TYPE_EVENT => $this->eventRepository->find($id),
            Note::TYPE_PRODUCT => $this->productRepository->find($id),
            Note::TYPE_ORDER => $this->salesOrderRepository->find($id),
        };
    }

    private function redirectForNoteable(string $type, int $id): Response
    {
        return match ($type) {
            Note::TYPE_MEMBER => $this->redirectToRoute('app_admin_user_show', ['id' => $id]),
            Note::TYPE_ATTENDEE => $this->redirectToRoute('app_admin_booking_edit', ['id' => $id]),
            Note::TYPE_EVENT => $this->redirectToRoute('app_admin_event_show', ['id' => $id]),
            Note::TYPE_PRODUCT => $this->redirectToRoute('app_admin_settings_product_edit', ['id' => $id]),
            Note::TYPE_ORDER => $this->redirectToRoute('app_admin_sale_show', ['id' => $id]),
        };
    }
}
