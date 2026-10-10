<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

/** The public news list and post pages — published posts only. */
class NewsControllerTest extends FunctionalTestCase
{
    public function testPublishedPostIsListedAndViewable(): void
    {
        $post = $this->createNewsPost();

        $this->assertPageLoads('/news');
        $this->assertSee($post->getTitle());

        $this->assertPageLoads('/news/' . $post->getSlug());
        $this->assertSee($post->getTitle());
    }

    public function testDraftPostIsNotPublic(): void
    {
        $draft = $this->createNewsPost(published: false);

        $this->get('/news/' . $draft->getSlug());
        self::assertResponseStatusCodeSame(404);
    }
}
