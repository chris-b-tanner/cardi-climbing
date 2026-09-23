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
use App\Service\ContactNoteMailer;
use App\Service\ContactQuickEmailMailer;
use App\Service\NoteableResolver;
use App\Service\NoteAssignmentMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly NoteableResolver $noteableResolver,
        private readonly NoteAssignmentMailer $noteAssignmentMailer,
        private readonly ContactNoteMailer $contactNoteMailer,
        private readonly ContactQuickEmailMailer $contactQuickEmailMailer,
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

        // "Email {address}" on the member page's add-note form — see ContactQuickEmailMailer. The
        // note stores the "Emailed: " prefix so the list makes clear this one actually went out,
        // but the email itself carries the plain typed text, not that prefix. Subject is required
        // whenever this is ticked (enforced client-side too — see _notes_panel.html.twig — but
        // checked again here since a form can always be submitted with JS disabled or bypassed).
        $wantsEmail = $noteableType === Note::TYPE_MEMBER
            && $request->request->getBoolean('emailContact')
            && $noteable->getEmail();

        $subject = trim($request->request->get('subject', ''));

        if ($wantsEmail && $subject === '') {
            $this->addFlash('error', 'Enter a subject before emailing this note.');
            return $this->redirectForNoteable($noteableType, $noteableId);
        }

        if ($content !== '') {
            /** @var User $admin */
            $admin = $this->getUser();

            $note = new Note();
            $note->setNoteable($noteable);
            $note->setContent($wantsEmail ? 'Emailed: ' . $content : $content);
            $note->setAddedBy($admin);

            if ($request->request->getBoolean('pinned')) {
                $note->pin($admin);
            }

            $em->persist($note);
            $em->flush();

            // Keep whoever's handling this contact in the loop — see ContactNoteMailer. Only
            // member notes carry an assignee at all; the other four noteable types have nothing
            // to check here.
            if ($noteableType === Note::TYPE_MEMBER) {
                $target = $this->noteableResolver->resolve($note, absolute: true);
                $this->contactNoteMailer->sendNoteAdded($note, $noteable, $target, $admin);

                if ($wantsEmail) {
                    $this->contactQuickEmailMailer->send($noteable, $subject, $content);
                    $this->addFlash('success', 'Note added and emailed to ' . $noteable->getEmail() . '.');
                }
            }
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

        if ($note->getEmail() !== null) {
            $this->addFlash('error', 'This note records a sent email and can\'t be deleted — it\'s part of the audit trail.');
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

        if ($note->getEmail() !== null) {
            $this->addFlash('error', 'This note records a sent email and can\'t be pinned.');
            return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
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

    /** Assigns (or, with an empty userId, unassigns) a pinned note to someone on staff — anyone on the team can hand a note to anyone else, or to themselves. */
    #[Route('/{id}/assign', name: 'app_admin_note_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assign(Request $request, Note $note, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('note_assign_' . $note->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $userId = (int) $request->request->get('userId', 0);

        if (!$userId) {
            $note->setAssignedTo(null);
            $em->flush();
            $this->addFlash('success', 'Note unassigned.');
            return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
        }

        $assignee = $this->userRepository->find($userId);
        $isStaff  = $assignee && (in_array(User::ROLE_ADMIN, $assignee->getRoles(), true) || in_array(User::ROLE_TEAM, $assignee->getRoles(), true));

        if (!$isStaff) {
            $this->addFlash('error', 'Notes can only be assigned to a team member.');
            return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
        }

        $note->setAssignedTo($assignee);
        $em->flush();

        /** @var User $assignedBy */
        $assignedBy = $this->getUser();
        $target     = $this->noteableResolver->resolve($note, absolute: true);
        $this->noteAssignmentMailer->sendAssigned($note, $target, $assignedBy);

        $this->addFlash('success', 'Assigned to ' . $assignee->getDisplayName() . '.');
        return $this->redirectForNoteable($note->getNoteableType(), $note->getNoteableId());
    }

    /** Staff list for the assign-note modal — team and admin only, same set as Settings > Team. */
    #[Route('/staff', name: 'app_admin_note_staff_list', methods: ['GET'])]
    public function staffList(): JsonResponse
    {
        return new JsonResponse(array_map(
            static fn(User $u) => ['id' => $u->getId(), 'name' => $u->getDisplayName() ?: $u->getEmail()],
            $this->userRepository->findTeam('firstName'),
        ));
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
