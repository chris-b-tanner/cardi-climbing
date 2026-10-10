<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** Admin > News: the list of all posts, drafts included (writing them is NewsEditorController's job). */
class AdminNewsControllerTest extends FunctionalTestCase
{
    public function testTeamSeesDraftAndPublishedPosts(): void
    {
        $this->loginAsTeam();
        $draft     = $this->createNewsPost(published: false);
        $published = $this->createNewsPost();

        $this->assertPageLoads('/admin/news');
        $this->assertSee($draft->getTitle());
        $this->assertSee($published->getTitle());
    }

    public function testMembersCannotViewTheNewsAdmin(): void
    {
        $this->loginAsMember();

        $this->assertForbidden('/admin/news');
    }
}
