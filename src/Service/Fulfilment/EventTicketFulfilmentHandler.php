<?php

namespace App\Service\Fulfilment;

use App\Entity\Attendee;
use App\Entity\Product;
use App\Entity\SalesOrderRow;
use App\Service\DoorAccessService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Selling an event ticket product books the beneficiary onto the linked Event — onto the specific
 * occurrence named on the row if the event is recurring.
 */
class EventTicketFulfilmentHandler implements FulfilmentHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DoorAccessService $doorAccessService,
    ) {}

    public function supports(string $productType): bool
    {
        return $productType === Product::TYPE_EVENT_TICKET;
    }

    public function fulfil(SalesOrderRow $row): void
    {
        $event = $row->getProduct()->getEventTicketProduct()->getEvent();

        if ($event->isRecurring() && !$row->getOccurrenceDate()) {
            throw new \LogicException(sprintf('Event ticket row for recurring event #%d has no occurrence date to book.', $event->getId()));
        }

        // One Attendee per seat — a row's qty is only ever >1 for an unrestricted event (see
        // SalesOrderService::assertValidRows()), where extra seats are anonymous places under the
        // same buyer rather than named/certified individuals.
        for ($i = 0; $i < $row->getQty(); $i++) {
            $attendee = new Attendee();
            $attendee->setEvent($event);
            $attendee->setUser($row->getEffectiveBeneficiary());
            $attendee->setOccurrenceDate($event->isRecurring() ? $row->getOccurrenceDate() : null);
            $attendee->setStatus(Attendee::STATUS_CONFIRMED);
            $attendee->setSalesOrderRow($row);
            $attendee->setPaidAmount($row->getChargedPrice());

            $this->doorAccessService->generatePinIfNeeded($attendee);

            $this->em->persist($attendee);
        }
    }
}
