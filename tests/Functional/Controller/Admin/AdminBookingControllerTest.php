<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Attendee;
use App\Tests\Support\FunctionalTestCase;

/** Admin > Bookings: the list, the manual booking form, editing a booking's status, and deleting one. */
class AdminBookingControllerTest extends FunctionalTestCase
{
    public function testListAndNewBookingPagesLoad(): void
    {
        $this->loginAsTeam();
        $member = $this->createUser();

        $this->assertPageLoads('/admin/bookings');
        $this->assertPageLoads('/admin/bookings/new?userId=' . $member->getId());
    }

    public function testNewBookingWithoutAMemberGoesBackToTheList(): void
    {
        $this->loginAsTeam();

        $this->get('/admin/bookings/new');
        $this->followRedirectAndAssertSee('Choose a member to check in from their contact page.');
    }

    public function testAdminCanCancelABookingFromItsEditPage(): void
    {
        $this->loginAsAdmin();
        $attendee = $this->createAttendee($this->createEvent(), $this->createUser());

        $editUrl = '/admin/bookings/' . $attendee->getId() . '/edit';
        $this->submitFormAt($editUrl, $editUrl, ['status' => Attendee::STATUS_CANCELLED]);
        $this->followRedirectAndAssertSee('Booking updated.');

        $this->em()->clear();
        self::assertSame(Attendee::STATUS_CANCELLED, $this->em()->find(Attendee::class, $attendee->getId())->getStatus());
    }

    public function testAdminCanDeleteAnUnpaidBooking(): void
    {
        $this->loginAsAdmin();
        $attendee = $this->createAttendee($this->createEvent(), $this->createUser());
        $id       = $attendee->getId();

        $this->submitFormAt('/admin/bookings/' . $id . '/edit', '/admin/bookings/' . $id . '/delete');
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(Attendee::class, $id));
    }
}
