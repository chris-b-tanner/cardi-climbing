<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** The admin landing page. */
class AdminDashboardControllerTest extends FunctionalTestCase
{
    public function testRequiresLogin(): void
    {
        $this->assertRequiresLogin('/admin/dashboard');
    }

    public function testMembersCannotViewTheDashboard(): void
    {
        $this->loginAsMember();

        $this->assertForbidden('/admin/dashboard');
    }

    public function testTeamCanViewTheDashboard(): void
    {
        $this->loginAsTeam();

        $this->assertPageLoads('/admin/dashboard');
    }
}
