<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Payment;
use App\Tests\Support\FunctionalTestCase;

/** Admin > Payments: the list, its CSV export, and deleting a payment that never succeeded. */
class AdminPaymentControllerTest extends FunctionalTestCase
{
    public function testTeamCanListAndExportPayments(): void
    {
        $this->loginAsTeam();

        $this->assertPageLoads('/admin/payments');

        $this->get('/admin/payments/export');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
    }

    public function testAdminCanDeleteAPendingPayment(): void
    {
        $this->loginAsAdmin();
        $payment = $this->createPayment($this->createUser());
        $id      = $payment->getId();

        $this->submitFormAt('/admin/payments', '/admin/payments/' . $id . '/delete');
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(Payment::class, $id));
    }
}
