<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** The team's "Actions" to-do list of assigned notes, plus its live search endpoint. */
class AdminActionsControllerTest extends FunctionalTestCase
{
    public function testRequiresLogin(): void
    {
        $this->assertRequiresLogin('/admin/actions');
    }

    public function testTeamCanViewAndSearchActions(): void
    {
        $this->loginAsTeam();

        $this->assertPageLoads('/admin/actions');

        $this->get('/admin/actions/search?q=phpunit');
        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
    }
}
