<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** Settings > Door log: diagnostic logs uploaded by the door controller. Admin only. */
class AdminDoorLogControllerTest extends FunctionalTestCase
{
    public function testAdminCanViewTheDoorLog(): void
    {
        $this->loginAsAdmin();

        $this->assertPageLoads('/admin/settings/door-log');
    }

    public function testTeamMembersCannotViewTheDoorLog(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/door-log');
    }
}
