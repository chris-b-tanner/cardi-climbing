<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;

/** Settings > Team: who has staff access, and taking it away. Admin only (security.yaml access_control). */
class AdminTeamControllerTest extends FunctionalTestCase
{
    public function testTeamMembersCannotManageTheTeam(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/team');
    }

    public function testAdminCanRemoveSomeoneFromTheTeam(): void
    {
        $this->loginAsAdmin();
        $teamMember = $this->createUser(roles: [User::ROLE_TEAM]);

        $this->assertPageLoads('/admin/settings/team');

        $this->submitFormAt('/admin/settings/team?role=team', '/admin/settings/team/' . $teamMember->getId() . '/remove');
        $this->followRedirectAndAssertSee('is no longer on the team');

        $this->em()->clear();
        self::assertNotContains(User::ROLE_TEAM, $this->em()->find(User::class, $teamMember->getId())->getRoles());
    }
}
