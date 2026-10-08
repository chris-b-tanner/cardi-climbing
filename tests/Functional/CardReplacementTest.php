<?php

namespace App\Tests\Functional;

use App\Entity\AccessCard;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\AccessCardRepository;
use App\Repository\NoteRepository;
use App\Tests\Support\CreatesTestAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The lost-card path (card_access.md § Lost or stolen card): an admin locks the member's card,
 * then links a replacement through the card station. Driven through the real admin pages and the
 * station's scan endpoint. Regression for a bug where linking only replaced an *active* card, so
 * the locked one stayed current alongside the new one — after which the member's admin page (and
 * Lock/Unlock/Remove) failed with a NonUniqueResultException.
 */
class CardReplacementTest extends WebTestCase
{
    use CreatesTestAdmin;

    private KernelBrowser $client;
    private ?int $userId = null;

    public function testLockedCardIsReplacedWhenANewCardIsLinked(): void
    {
        $this->client = static::createClient();
        $this->client->loginUser($this->findOrCreateAdmin());
        $this->userId = $this->createMember();

        $oldUid = $this->randomUid();
        $newUid = $this->randomUid();

        $this->linkViaStation($oldUid);
        $this->postCardAction('card-lock');
        self::assertSame(AccessCard::STATUS_LOCKED, $this->card($oldUid)->getStatus());

        $this->linkViaStation($newUid);

        self::assertSame(AccessCard::STATUS_REPLACED, $this->card($oldUid)->getStatus(), 'The locked card should be retired, not left current.');
        self::assertSame(AccessCard::STATUS_ACTIVE, $this->card($newUid)->getStatus());

        $member = $this->em()->getRepository(User::class)->find($this->userId);
        self::assertSame($newUid, static::getContainer()->get(AccessCardRepository::class)->findCurrentForUser($member)?->getUid());

        // The member page — which failed outright with two current cards — loads and shows only the new, unlocked card.
        $content = $this->showMemberPage()->filter('body')->html();
        self::assertStringContainsString($newUid, $content);
        self::assertStringNotContainsString('· Locked', $content);

        $notes = array_map(
            static fn (Note $note) => $note->getContent(),
            static::getContainer()->get(NoteRepository::class)->findForNoteable(Note::TYPE_MEMBER, $this->userId),
        );
        self::assertContains('Access card replaced: ' . $oldUid . ' → ' . $newUid . '.', $notes);

        // Lock still works on the new card (it also failed with two current cards).
        $this->postCardAction('card-lock');
        self::assertSame(AccessCard::STATUS_LOCKED, $this->card($newUid)->getStatus());
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            $em = $this->em();
            $em->clear();

            foreach (static::getContainer()->get(NoteRepository::class)->findForNoteable(Note::TYPE_MEMBER, $this->userId) as $note) {
                $em->remove($note);
            }
            if ($user = $em->getRepository(User::class)->find($this->userId)) {
                $em->remove($user); // cascades to its AccessCard and CardLinkSession rows
            }
            $em->flush();
        }

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function createMember(): int
    {
        $em = $this->em();

        $user = new User();
        $user->setEmail(sprintf('phpunit-card-replace-%s@example.test', bin2hex(random_bytes(4))));
        $user->setFirstName('Card');
        $user->setLastName('Replacement');
        $user->setPassword('unused — never logged into');
        $em->persist($user);
        $em->flush();

        return $user->getId();
    }

    private function showMemberPage(): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/admin/users/' . $this->userId);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Admin presses "Scan card" on the member's page, then {uid} is tapped on the station. */
    private function linkViaStation(string $uid): void
    {
        $csrf = $this->showMemberPage()->filter('button[data-mode="link"]')->attr('data-csrf');

        $this->client->request('POST', '/admin/users/' . $this->userId . '/card-scan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['mode' => 'link', '_csrf_token' => $csrf]),
        );
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/v1/card-station/scan', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($_ENV['CARD_STATION_API_KEY'] ?? 'change-me'),
            'CONTENT_TYPE'       => 'application/json',
        ], content: json_encode(['card_uid' => $uid]));
        self::assertResponseIsSuccessful();
        self::assertSame('linked', json_decode($this->client->getResponse()->getContent(), true)['result']);
    }

    /** Submits the member page's Lock/Unlock/Remove form for {action} (e.g. 'card-lock'). */
    private function postCardAction(string $action): void
    {
        $path = '/admin/users/' . $this->userId . '/' . $action;
        $csrf = $this->showMemberPage()->filter('form[action="' . $path . '"] input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', $path, ['_csrf_token' => $csrf]);
        self::assertResponseRedirects('/admin/users/' . $this->userId);
    }

    private function card(string $uid): AccessCard
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(AccessCard::class)->findOneBy(['uid' => $uid]);
    }

    private function randomUid(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }
}
