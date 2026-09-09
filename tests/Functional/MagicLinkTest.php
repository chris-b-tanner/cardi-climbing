<?php

namespace App\Tests\Functional;

use App\Entity\MagicLink;
use App\Entity\User;
use App\Service\MagicLinkService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A magic link should log the recipient straight in and send them on to the page it was generated
 * for — the whole point being that a contact with no password (most are created by an admin
 * without one) can still get into their account via an emailed link. See MagicLinkService.
 */
class MagicLinkTest extends WebTestCase
{
    private ?int $testUserId = null;

    public function testValidTokenLogsInAndRedirectsToTargetPath(): void
    {
        $client = static::createClient();
        $user   = $this->createTestUser();

        $magicLinkService = static::getContainer()->get(MagicLinkService::class);
        $token = $magicLinkService->generate($user, '/account#certifications');

        $client->request('GET', '/go/' . $token);
        self::assertResponseRedirects('/account#certifications');

        // Confirm the session is actually authenticated as this user now — a page that requires
        // login should render rather than bounce to the login form.
        $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($user->getEmail(), $client->getResponse()->getContent());
    }

    public function testInvalidTokenRedirectsToLoginWithoutAuthenticating(): void
    {
        $client = static::createClient();

        $client->request('GET', '/go/' . str_repeat('a', 96));
        self::assertResponseRedirects('/login');

        $client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testTamperedVerifierIsRejected(): void
    {
        $client = static::createClient();
        $user   = $this->createTestUser();

        $magicLinkService = static::getContainer()->get(MagicLinkService::class);
        $token = $magicLinkService->generate($user, '/account');

        $tampered = substr($token, 0, 32) . str_repeat('0', 64);

        $client->request('GET', '/go/' . $tampered);
        self::assertResponseRedirects('/login');
    }

    private function createTestUser(): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setEmail(sprintf('phpunit-magiclink-%s@example.test', bin2hex(random_bytes(4))));
        $user->setPassword('unused — magic links bypass password checking entirely');
        $user->setRoles([User::ROLE_MEMBER]);
        $user->setFirstName('Magic');
        $user->setLastName('Link');

        $em->persist($user);
        $em->flush();

        $this->testUserId = $user->getId();

        return $user;
    }

    protected function tearDown(): void
    {
        if ($this->testUserId !== null) {
            /** @var EntityManagerInterface $em */
            $em   = static::getContainer()->get('doctrine')->getManager();
            $user = $em->getRepository(User::class)->find($this->testUserId);

            if ($user) {
                foreach ($em->getRepository(MagicLink::class)->findBy(['user' => $user]) as $link) {
                    $em->remove($link);
                }
                $em->remove($user);
                $em->flush();
            }
        }

        parent::tearDown();
    }
}
