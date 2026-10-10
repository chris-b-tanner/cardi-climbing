<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** Settings > Access log — admin only. */
class AdminAccessEventControllerTest extends FunctionalTestCase
{
    public function testAdminCanViewTheAccessLog(): void
    {
        $this->loginAsAdmin();

        $this->assertPageLoads('/admin/settings/access-log');
    }

    public function testTeamMembersCannotViewTheAccessLog(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/access-log');
    }
}
