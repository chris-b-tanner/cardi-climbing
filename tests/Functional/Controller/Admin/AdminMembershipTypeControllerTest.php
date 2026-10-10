<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\MembershipType;
use App\Tests\Support\FunctionalTestCase;

/** Settings > Membership types. Admin only. */
class AdminMembershipTypeControllerTest extends FunctionalTestCase
{
    public function testTeamMembersCannotManageMembershipTypes(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/membership-types');
    }

    public function testAdminCanCreateAMembershipType(): void
    {
        $this->loginAsAdmin();
        $this->assertPageLoads('/admin/settings/membership-types');

        $name = 'PHPUnit membership ' . $this->uniqueSuffix();
        $this->submitFormAt('/admin/settings/membership-types/new', '/admin/settings/membership-types/new', [
            'name'     => $name,
            'duration' => MembershipType::DURATION_MONTH,
        ]);
        $this->followRedirectAndAssertSee('Membership type created.');

        $membershipType = $this->track($this->em()->getRepository(MembershipType::class)->findOneBy(['name' => $name]));
        self::assertSame(MembershipType::DURATION_MONTH, $membershipType->getDuration());

        $this->assertPageLoads('/admin/settings/membership-types/' . $membershipType->getId() . '/edit');
    }
}
