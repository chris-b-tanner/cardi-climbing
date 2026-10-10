<?php

namespace App\Tests\Functional\Controller\Web;

use App\Entity\NewsPost;
use App\Tests\Support\FunctionalTestCase;

/** Writing news posts in the on-site WYSIWYG editor. Team only. */
class NewsEditorControllerTest extends FunctionalTestCase
{
    public function testMembersCannotWriteNews(): void
    {
        $this->loginAsMember();

        $this->assertForbidden('/news/new');
    }

    public function testTeamCanCreateAndEditAPost(): void
    {
        $this->loginAsTeam();

        $title = 'PHPUnit editor post ' . $this->uniqueSuffix();
        $this->submitFormAt('/news/new', '/news/new', [
            'title' => $title,
            'body'  => '<p>Written by PHPUnit.</p>',
        ]);
        $this->followRedirectAndAssertSee('News post created.');

        $post = $this->track($this->em()->getRepository(NewsPost::class)->findOneBy(['title' => $title]));
        self::assertNotNull($post);
        self::assertNull($post->getPublishedAt(), 'Unticked "published" should save a draft.');

        $editUrl = '/news/' . $post->getId() . '/edit';
        $this->submitFormAt($editUrl, $editUrl, ['published' => true]);
        $this->followRedirectAndAssertSee('News post updated.');

        $this->em()->clear();
        self::assertNotNull($this->em()->find(NewsPost::class, $post->getId())->getPublishedAt());
    }

    public function testMissingBodyIsReported(): void
    {
        $this->loginAsTeam();

        $this->submitFormAt('/news/new', '/news/new', ['title' => 'PHPUnit no body', 'body' => '']);
        self::assertResponseIsSuccessful();
        $this->assertSee('Body is required');
    }
}
