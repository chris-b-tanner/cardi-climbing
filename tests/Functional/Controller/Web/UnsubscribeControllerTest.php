<?php

namespace App\Tests\Functional\Controller\Web;

use App\Entity\User;
use App\Service\UnsubscribeToken;
use App\Tests\Support\FunctionalTestCase;

/** The email-footer unsubscribe link, and RFC 8058 one-click unsubscribe (a POST to the same URL). */
class UnsubscribeControllerTest extends FunctionalTestCase
{
    public function testValidLinkOptsTheMemberOut(): void
    {
        $member = $this->createUser();
        $member->setOptIn(true);
        $this->em()->flush();

        $token = $this->service(UnsubscribeToken::class)->generate($member->getEmail());
        $this->client->request('POST', '/unsubscribe?' . http_build_query(['email' => $member->getEmail(), 'token' => $token]));
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertFalse($this->em()->find(User::class, $member->getId())->isOptIn());
    }

    public function testTamperedLinkChangesNothing(): void
    {
        $member = $this->createUser();
        $member->setOptIn(true);
        $this->em()->flush();

        $this->get('/unsubscribe?' . http_build_query(['email' => $member->getEmail(), 'token' => str_repeat('0', 64)]));
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertTrue($this->em()->find(User::class, $member->getId())->isOptIn());
    }
}
