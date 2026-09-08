<?php

namespace App\Service;

use App\Entity\Note;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\ProductRepository;
use App\Repository\SalesOrderRepository;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Turns a Note's noteableType/noteableId into a human label and an admin URL, for the Actions report. */
class NoteableResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly EventRepository $eventRepository,
        private readonly ProductRepository $productRepository,
        private readonly SalesOrderRepository $salesOrderRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array{label: string, url: ?string} */
    public function resolve(Note $note): array
    {
        $id = $note->getNoteableId();

        return match ($note->getNoteableType()) {
            Note::TYPE_MEMBER => $this->resolveMember($id),
            Note::TYPE_ATTENDEE => $this->resolveAttendee($id),
            Note::TYPE_EVENT => $this->resolveEvent($id),
            Note::TYPE_PRODUCT => $this->resolveProduct($id),
            Note::TYPE_ORDER => $this->resolveOrder($id),
            default => ['label' => 'Unknown record', 'url' => null],
        };
    }

    private function resolveMember(int $id): array
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            return ['label' => 'Member #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $user->getDisplayName(),
            'url'   => $this->urlGenerator->generate('app_admin_user_show', ['id' => $id]),
        ];
    }

    private function resolveAttendee(int $id): array
    {
        $attendee = $this->attendeeRepository->find($id);
        if (!$attendee) {
            return ['label' => 'Booking #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $attendee->getUser()->getDisplayName() . ' — ' . $attendee->getEvent()->getTitle(),
            'url'   => $this->urlGenerator->generate('app_admin_booking_edit', ['id' => $id]),
        ];
    }

    private function resolveEvent(int $id): array
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            return ['label' => 'Event #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $event->getTitle() . ' — ' . $event->getDate()->format('d M Y'),
            'url'   => $this->urlGenerator->generate('app_admin_event_show', ['id' => $id]),
        ];
    }

    private function resolveProduct(int $id): array
    {
        $product = $this->productRepository->find($id);
        if (!$product) {
            return ['label' => 'Product #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $product->getName() . ($product->getVariantValue() ? ' (' . $product->getVariantValue() . ')' : ''),
            'url'   => $this->urlGenerator->generate('app_admin_settings_product_edit', ['id' => $id]),
        ];
    }

    private function resolveOrder(int $id): array
    {
        $order = $this->salesOrderRepository->find($id);
        if (!$order) {
            return ['label' => 'Sale #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => 'Sale #' . $id,
            'url'   => $this->urlGenerator->generate('app_admin_sale_show', ['id' => $id]),
        ];
    }
}
