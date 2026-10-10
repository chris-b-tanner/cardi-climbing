<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

/** The self-serve cart. Never gets as far as a real Stripe PaymentIntent. */
class CartControllerTest extends FunctionalTestCase
{
    public function testRequiresLogin(): void
    {
        $this->assertRequiresLogin('/cart');
    }

    public function testEmptyCartShowsNoCheckout(): void
    {
        $this->loginAsMember();

        $this->assertPageLoads('/cart');
        $this->assertSee('Your cart is empty.');
    }

    public function testCheckoutWithoutACsrfTokenIsRejected(): void
    {
        $this->loginAsMember();

        $this->client->request('POST', '/cart/checkout');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotPollSomeoneElsesPayment(): void
    {
        $this->loginAsMember();
        $payment = $this->createPayment($this->createUser());

        $this->get('/cart/status/' . $payment->getId());
        self::assertResponseStatusCodeSame(404);
    }
}
