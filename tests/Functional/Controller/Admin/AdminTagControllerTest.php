<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Tag;
use App\Tests\Support\FunctionalTestCase;

/** Settings > Tags: admin-only, with the full create → edit → delete cycle. */
class AdminTagControllerTest extends FunctionalTestCase
{
    public function testTeamMembersCannotManageTags(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/tags');
    }

    public function testAdminCanCreateEditAndDeleteATag(): void
    {
        $this->loginAsAdmin();
        $this->assertPageLoads('/admin/settings/tags');

        $name = 'PHPUnit tag ' . $this->uniqueSuffix();
        $this->submitFormAt('/admin/settings/tags/new', '/admin/settings/tags/new', ['name' => $name]);
        $this->followRedirectAndAssertSee('Tag created.');

        $tag = $this->track($this->em()->getRepository(Tag::class)->findOneBy(['name' => $name]));
        self::assertNotNull($tag);

        $editUrl = '/admin/settings/tags/' . $tag->getId() . '/edit';
        $this->submitFormAt($editUrl, $editUrl, ['name' => $name . ' renamed']);
        $this->followRedirectAndAssertSee('Tag updated.');

        $deleteUrl = '/admin/settings/tags/' . $tag->getId() . '/delete';
        $this->submitFormAt($editUrl, $deleteUrl);
        $this->followRedirectAndAssertSee('deleted and removed from all members');

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(Tag::class)->find($tag->getId()));
    }
}
