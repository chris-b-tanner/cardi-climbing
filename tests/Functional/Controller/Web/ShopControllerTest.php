<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

/** The self-serve shop, and its add-to-cart endpoint (plain POST from the shop, AJAX from the event preview). */
class ShopControllerTest extends FunctionalTestCase
{
    public function testRequiresLogin(): void
    {
        $this->assertRequiresLogin('/shop');
    }

    public function testMemberCanViewTheShop(): void
    {
        $this->loginAsMember();

        $this->assertPageLoads('/shop');
    }

    public function testAddingWithoutACsrfTokenIsRejected(): void
    {
        $this->loginAsMember();

        $this->client->xmlHttpRequest('POST', '/shop/add', ['productId' => 1]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Access denied.', $this->json()['error']);
    }
}
