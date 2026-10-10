<?php

namespace App\Tests\Functional\Controller\Web;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Repository\AttendeeRepository;
use App\Tests\Support\FunctionalTestCase;

/** The public events calendar and event pages, and a member booking onto (then cancelling) an open event. */
class EventControllerTest extends FunctionalTestCase
{
    public function testCalendarAndEventPagesLoadForAnonymousVisitors(): void
    {
        $event = $this->createEvent();
        $date  = $event->getDate()->format('Y-m-d');

        $this->assertPageLoads('/events?date=' . $date);
        $this->assertSee($event->getTitle());

        $this->assertPageLoads('/events?q=' . urlencode($event->getTitle()));
        $this->assertPageLoads('/events/' . $event->getId());
        $this->assertPageLoads('/events/' . $event->getId() . '/preview?date=' . $date);
        $this->assertPageLoads('/events/' . $event->getId() . '/details');
    }

    public function testDraftEventsAreHiddenFromThePublic(): void
    {
        $event = $this->createEvent(status: Event::STATUS_DRAFT);

        $this->get('/events/' . $event->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testMemberCanBookAndCancelAnOpenEvent(): void
    {
        $member = $this->loginAsMember();
        $event  = $this->createEvent();

        $this->submitFormAt('/events/' . $event->getId(), '/events/' . $event->getId() . '/book');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $booking = $this->service(AttendeeRepository::class)->findActiveBooking($event, $member, null);
        self::assertNotNull($booking, 'Booking should have been created.');
        $this->track($booking);

        $this->submitFormAt('/account', '/events/' . $event->getId() . '/cancel');
        $this->followRedirectAndAssertSee('Your booking has been cancelled.');

        $this->em()->clear();
        self::assertSame(Attendee::STATUS_CANCELLED, $this->em()->find(Attendee::class, $booking->getId())->getStatus());
    }
}
