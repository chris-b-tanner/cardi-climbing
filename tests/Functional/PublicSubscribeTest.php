<?php

namespace App\Tests\Functional;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Drives the public homepage's newsletter signup form end to end: a brand new email address
 * creates a contact and shows the success message, and — the case that broke in production before
 * (see SubscribeController) — signing up again with the same email is also a "good" response
 * (success message, no error, no duplicate contact), not a crash or a rejection.
 */
class PublicSubscribeTest extends WebTestCase
{
    private ?string $testEmail = null;

    public function testNewEmailSubscribesSuccessfully(): void
    {
        $client = static::createClient();
        $this->testEmail = sprintf('phpunit-subscriber-%s@example.test', bin2hex(random_bytes(4)));

        $this->submitSubscribeForm($client, 'Testy', 'McTestface', $this->testEmail);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("we&#039;ll keep you in the loop", $client->getResponse()->getContent());

        $user = $this->findUserByEmail($this->testEmail);
        self::assertNotNull($user, 'Subscribing should have created a contact.');
        self::assertSame('Testy', $user->getFirstName());
        self::assertSame('McTestface', $user->getLastName());
        self::assertTrue($user->isOptIn());
    }

    public function testDuplicateEmailStillGetsAGoodResponse(): void
    {
        $client = static::createClient();
        $this->testEmail = sprintf('phpunit-subscriber-%s@example.test', bin2hex(random_bytes(4)));

        // First signup creates the contact.
        $this->submitSubscribeForm($client, 'Testy', 'McTestface', $this->testEmail);
        self::assertResponseIsSuccessful();

        $firstUser = $this->findUserByEmail($this->testEmail);
        self::assertNotNull($firstUser);

        // Signing up again with the same email — e.g. someone who forgot they'd already done so —
        // must not error out or reject them; it should look and behave like a fresh success.
        $this->submitSubscribeForm($client, 'Testy', 'McTestface', $this->testEmail);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("we&#039;ll keep you in the loop", $client->getResponse()->getContent());
        self::assertStringNotContainsString('subscribe-form__msg--error', $client->getResponse()->getContent());

        $matchingUsers = static::getContainer()->get(UserRepository::class)->findBy(['email' => $this->testEmail]);
        self::assertCount(1, $matchingUsers, 'Re-subscribing the same email must not create a duplicate contact.');
        self::assertSame($firstUser->getId(), $matchingUsers[0]->getId());
    }

    private function submitSubscribeForm(KernelBrowser $client, string $firstName, string $lastName, string $email): void
    {
        $crawler = $client->request('GET', '/');
        $form    = $crawler->selectButton('Stay in the loop')->form([
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'email'     => $email,
        ]);

        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    private function findUserByEmail(string $email): ?User
    {
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    protected function tearDown(): void
    {
        if ($this->testEmail !== null) {
            /** @var EntityManagerInterface $em */
            $em   = static::getContainer()->get('doctrine')->getManager();
            $user = $em->getRepository(User::class)->findOneBy(['email' => $this->testEmail]);

            if ($user) {
                $noteRepository = static::getContainer()->get(NoteRepository::class);
                foreach ($noteRepository->findForNoteable(Note::TYPE_MEMBER, $user->getId()) as $note) {
                    $em->remove($note);
                }

                $em->remove($user);
                $em->flush();
            }
        }

        parent::tearDown();
    }
}
