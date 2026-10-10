<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** Admin > Updates: the feed of recent notes across all records, with filters and an AJAX list refresh. */
class AdminRecentUpdatesControllerTest extends FunctionalTestCase
{
    public function testTeamCanViewAndFilterRecentUpdates(): void
    {
        $team = $this->loginAsTeam();

        $this->assertPageLoads('/admin/updates');
        $this->assertPageLoads('/admin/updates?q=phpunit&addedBy=' . $team->getId());

        $this->client->xmlHttpRequest('GET', '/admin/updates?q=phpunit');
        self::assertResponseIsSuccessful();
    }
}
