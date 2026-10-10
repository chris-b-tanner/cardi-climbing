<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\UserCertification;
use App\Tests\Support\FunctionalTestCase;

/** Admin > Certifications: every member's certification records, filterable by status, with an AJAX list refresh. */
class AdminCertificationControllerTest extends FunctionalTestCase
{
    public function testTeamCanListAndFilterCertifications(): void
    {
        $this->loginAsTeam();

        $this->assertPageLoads('/admin/certifications');
        $this->assertPageLoads('/admin/certifications?status=' . UserCertification::STATUS_IN_PROGRESS . '&q=phpunit');

        $this->client->xmlHttpRequest('GET', '/admin/certifications?q=phpunit');
        self::assertResponseIsSuccessful();
    }
}
