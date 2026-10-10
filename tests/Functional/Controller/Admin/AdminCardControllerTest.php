<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** Settings > Cards: every NFC card the system has seen. Admin only. */
class AdminCardControllerTest extends FunctionalTestCase
{
    public function testAdminCanViewTheCardList(): void
    {
        $this->loginAsAdmin();

        $this->assertPageLoads('/admin/settings/cards');
    }

    public function testUnknownCardIsNotFound(): void
    {
        $this->loginAsAdmin();

        $this->get('/admin/settings/cards/' . strtoupper(bin2hex(random_bytes(8))));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTeamMembersCannotViewCards(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/cards');
    }
}
