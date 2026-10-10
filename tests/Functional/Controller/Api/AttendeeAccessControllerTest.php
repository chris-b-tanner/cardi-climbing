<?php

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Attendee;
use App\Tests\Support\FunctionalTestCase;

/** Manual reception check-in for a self-access booking — the "Check in now" button on the admin booking page. */
class AttendeeAccessControllerTest extends FunctionalTestCase
{
    public function testStaffCanCheckInAnAttendee(): void
    {
        $this->loginAsTeam();
        $attendee = $this->createSelfAccessAttendee();

        $crawler = $this->assertPageLoads('/admin/bookings/' . $attendee->getId() . '/edit');
        $csrf    = $crawler->filter('#door-access-checkin')->attr('data-csrf');

        $this->client->request('POST', '/v1/attendees/' . $attendee->getId() . '/check-in', ['_csrf_token' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame(Attendee::CHECKED_IN_MANUAL, $this->json()['checked_in_method']);

        // A second check-in is a conflict, not a silent overwrite of the first.
        $this->client->request('POST', '/v1/attendees/' . $attendee->getId() . '/check-in', ['_csrf_token' => $csrf]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCheckInWithoutACsrfTokenIsRejected(): void
    {
        $this->loginAsTeam();
        $attendee = $this->createSelfAccessAttendee();

        $this->client->request('POST', '/v1/attendees/' . $attendee->getId() . '/check-in');
        self::assertResponseStatusCodeSame(403);
    }

    private function createSelfAccessAttendee(): Attendee
    {
        $event = $this->createEvent(date: new \DateTimeImmutable('today'));
        $event->setIsSelfAccess(true);
        $this->em()->flush();

        return $this->createAttendee($event, $this->createUser());
    }
}
