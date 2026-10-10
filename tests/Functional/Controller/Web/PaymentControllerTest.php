<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

/** The public donation page. Only paths that stop before creating a Stripe PaymentIntent are exercised. */
class PaymentControllerTest extends FunctionalTestCase
{
    public function testDonatePageLoadsWithoutLogin(): void
    {
        $this->assertPageLoads('/donate');
    }

    public function testInvalidAmountIsRejected(): void
    {
        $this->assertPageLoads('/donate');
        $csrf = $this->jsConstant('csrfToken');

        foreach (['', 'abc', '0.50', '9999'] as $amount) {
            $this->client->request('POST', '/donate/intent', ['_csrf_token' => $csrf, 'amount' => $amount]);
            self::assertResponseStatusCodeSame(422, sprintf('Amount "%s" should be rejected.', $amount));
        }
    }

    public function testAnonymousDonorMustGiveNameAndEmail(): void
    {
        $this->assertPageLoads('/donate');

        $this->client->request('POST', '/donate/intent', ['_csrf_token' => $this->jsConstant('csrfToken'), 'amount' => '10']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('name and a valid email', $this->json()['error']);
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/donate/intent', ['amount' => '10']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotPollSomeoneElsesDonation(): void
    {
        $this->loginAsMember();
        $payment = $this->createPayment($this->createUser());

        $this->get('/donate/' . $payment->getId() . '/status');
        self::assertResponseStatusCodeSame(404);
    }
}
