<?php

namespace App\Service;

use App\Entity\Attendee;
use App\Entity\CreditLedgerEntry;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The "book without paying" rule for an event that accepts membership and/or credit as an access
 * method, shared by every path that can create such a booking directly (the public self-serve
 * "book now" button, and admin check-in): an active membership (the member's own, or — for a
 * dependent — the family's) covers the seat for free if the event accepts membership; otherwise a
 * spare drop-in credit is spent and linked to the booking if the event accepts credit.
 */
class EventBookingCreditService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Whether booking {event} for {user} needs a credit spent to cover it — false if the event
     * doesn't gate on membership/credit at all, or an active membership already covers it.
     *
     * @throws \InvalidArgumentException if the event needs cover and the member has neither an active membership nor a spare credit
     */
    public function requiresCredit(Event $event, User $user): bool
    {
        if (!$event->acceptsCredit() && !$event->acceptsMembership()) {
            return false;
        }

        if ($event->acceptsMembership()) {
            $membership = $user->getEffectiveMembership();
            if ($membership !== null && $membership->isCurrentlyActive()) {
                return false;
            }
        }

        if ($event->acceptsCredit() && $user->getCreditBalance() > 0) {
            return true;
        }

        throw new \InvalidArgumentException('This member needs an active membership or a spare credit to book this event.');
    }

    /** Spends one credit against {user}, linked to {attendee}. Call only after requiresCredit() has returned true and the attendee has been created (it doesn't need to be flushed yet). */
    public function spendCredit(User $user, Event $event, Attendee $attendee): void
    {
        $ledgerEntry = new CreditLedgerEntry();
        $ledgerEntry->setUser($user);
        $ledgerEntry->setCreditChange(-1);
        $ledgerEntry->setReason(CreditLedgerEntry::REASON_REDEMPTION);
        $ledgerEntry->setAttendee($attendee);
        $ledgerEntry->setNote('Booked "' . $event->getTitle() . '"');

        $this->em->persist($ledgerEntry);
    }
}
