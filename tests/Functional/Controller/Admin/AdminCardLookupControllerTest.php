<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** The "who owns this card?" lookup the admin user list polls while waiting for a tap on the card station. */
class AdminCardLookupControllerTest extends FunctionalTestCase
{
    public function testStatusReturnsJson(): void
    {
        $this->loginAsTeam();

        $this->get('/admin/card-lookup');
        self::assertResponseIsSuccessful();
        self::assertIsArray($this->json());
    }

    public function testStartingALookupWithoutACsrfTokenIsRejected(): void
    {
        $this->loginAsTeam();

        $this->client->request('POST', '/admin/card-lookup', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(403);
    }
}
