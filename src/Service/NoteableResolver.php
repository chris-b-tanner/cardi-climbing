<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\ProductRepository;
use App\Repository\SalesOrderRepository;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Turns a Note's noteableType/noteableId into a human label and an admin URL, for the Actions report and the assignment-notification email. */
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

    /** @return array{label: string, url: ?string, company?: ?string, tags?: string[]} */
    public function resolve(Note $note, bool $absolute = false): array
    {
        $id = $note->getNoteableId();

        return match ($note->getNoteableType()) {
            Note::TYPE_MEMBER => $this->resolveMember($id, $absolute),
            Note::TYPE_ATTENDEE => $this->resolveAttendee($id, $absolute),
            Note::TYPE_EVENT => $this->resolveEvent($id, $absolute),
            Note::TYPE_PRODUCT => $this->resolveProduct($id, $absolute),
            Note::TYPE_ORDER => $this->resolveOrder($id, $absolute),
            default => ['label' => 'Unknown record', 'url' => null],
        };
    }

    private function resolveMember(int $id, bool $absolute): array
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            return ['label' => 'Member #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label'   => $user->getDisplayName(),
            'url'     => $this->url('app_admin_user_show', ['id' => $id], $absolute),
            'company' => $user->getCompany(),
            'tags'    => $this->tagNames($user),
        ];
    }

    private function resolveAttendee(int $id, bool $absolute): array
    {
        $attendee = $this->attendeeRepository->find($id);
        if (!$attendee) {
            return ['label' => 'Booking #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $attendee->getUser()->getDisplayName() . ' — ' . $attendee->getEvent()->getTitle(),
            'url'   => $this->url('app_admin_booking_edit', ['id' => $id], $absolute),
            'tags'  => $this->tagNames($attendee->getUser()),
        ];
    }

    private function resolveEvent(int $id, bool $absolute): array
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            return ['label' => 'Event #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $event->getTitle() . ' — ' . $event->getDate()->format('d M Y'),
            'url'   => $this->url('app_admin_event_show', ['id' => $id], $absolute),
        ];
    }

    private function resolveProduct(int $id, bool $absolute): array
    {
        $product = $this->productRepository->find($id);
        if (!$product) {
            return ['label' => 'Product #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => $product->getName() . ($product->getVariantValue() ? ' (' . $product->getVariantValue() . ')' : ''),
            'url'   => $this->url('app_admin_settings_product_edit', ['id' => $id], $absolute),
        ];
    }

    private function resolveOrder(int $id, bool $absolute): array
    {
        $order = $this->salesOrderRepository->find($id);
        if (!$order) {
            return ['label' => 'Sale #' . $id . ' (deleted)', 'url' => null];
        }

        return [
            'label' => 'Sale #' . $id,
            'url'   => $this->url('app_admin_sale_show', ['id' => $id], $absolute),
            'tags'  => $this->tagNames($order->getUser()),
        ];
    }

    private function url(string $route, array $params, bool $absolute): string
    {
        return $this->urlGenerator->generate($route, $params, $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /** @return string[] */
    private function tagNames(User $user): array
    {
        return array_map(static fn ($tag) => $tag->getName(), $user->getTags()->toArray());
    }
}
