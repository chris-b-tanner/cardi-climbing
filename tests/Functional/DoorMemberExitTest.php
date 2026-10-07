<?php

namespace App\Tests\Functional;

use App\Entity\AccessCard;
use App\Entity\AccessEvent;
use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The exit reader (door-access-spec.md § Exit reader): the door's `exit_cards` list is every active
 * card tapped at the door in the last year, and a `member_exit` tap checks its member out of an
 * open session if they have one — otherwise it's just logged.
 */
class DoorMemberExitTest extends WebTestCase
{
    /** @var int[] */
    private array $userIds = [];
    private ?int $eventId = null;
    /** @var string[] */
    private array $accessEventIds = [];

    public function testExitCardsListsOnlyActiveCardsUsedInTheLastYear(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $now = new \DateTimeImmutable();

        $recent = $this->createUserWithCard($em, 'recent', $this->randomUid());
        $this->recordTap($em, $recent['uid'], $now->modify('-3 months'));

        $stale = $this->createUserWithCard($em, 'stale', $this->randomUid());
        $this->recordTap($em, $stale['uid'], $now->modify('-13 months'));

        $locked = $this->createUserWithCard($em, 'locked', $this->randomUid());
        $this->recordTap($em, $locked['uid'], $now->modify('-1 day'));
        $locked['card']->lock($locked['user']);

        $em->flush();

        $exitUids = array_column($this->getCredentials($client)['exit_cards'], 'card_uid');

        self::assertContains($recent['uid'], $exitUids, 'A card tapped within the last year should be on the exit list.');
        self::assertNotContains($stale['uid'], $exitUids, 'A card last tapped over a year ago should not be on the exit list.');
        self::assertNotContains($locked['uid'], $exitUids, 'A locked card should never be on the exit list.');
    }

    public function testMemberExitChecksOutAnOpenSessionAtTheTapTime(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $member = $this->createUserWithCard($em, 'exiting', $this->randomUid());
        $attendee = $this->createCheckedInAttendee($em, $member['user'], $now->modify('-1 hour'));
        $em->flush();

        $tappedAt = new \DateTimeImmutable('@' . ($now->getTimestamp() - 60)); // whole seconds, UTC — as the door sends it
        $eventId = $this->postMemberExit($client, $member['uid'], 'authorized', $tappedAt);

        $em->clear();
        $attendee = $em->getRepository(Attendee::class)->find($attendee->getId());
        self::assertNotNull($attendee->getCheckedOutAt());
        // DATETIME columns carry no zone, so compare the stored wall-clock value the door sent.
        self::assertSame($tappedAt->format('Y-m-d H:i:s'), $attendee->getCheckedOutAt()->format('Y-m-d H:i:s'));
        self::assertSame(Attendee::CHECKED_OUT_DOOR_CARD, $attendee->getCheckedOutMethod());

        $event = $em->getRepository(AccessEvent::class)->findOneBy(['eventId' => $eventId]);
        self::assertSame(AccessEvent::TYPE_MEMBER_EXIT, $event->getType());
        self::assertSame($attendee->getId(), $event->getAttendee()?->getId());
        self::assertSame($member['user']->getId(), $event->getCardUser()?->getId());
    }

    public function testMemberExitWithNoOpenSessionIsLoggedWithoutAnAttendee(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $member = $this->createUserWithCard($em, 'no-session', $this->randomUid());
        // Checked in two days ago and never tapped out — too old to be the session this tap closes.
        $stale = $this->createCheckedInAttendee($em, $member['user'], $now->modify('-2 days'));
        $em->flush();

        $eventId = $this->postMemberExit($client, $member['uid'], 'authorized', $now);

        $em->clear();
        self::assertNull($em->getRepository(Attendee::class)->find($stale->getId())->getCheckedOutAt());

        $event = $em->getRepository(AccessEvent::class)->findOneBy(['eventId' => $eventId]);
        self::assertNotNull($event, 'The tap should still be recorded.');
        self::assertNull($event->getAttendee());
        self::assertSame($member['user']->getId(), $event->getCardUser()?->getId());
    }

    protected function tearDown(): void
    {
        $em = $this->em();

        foreach ($this->accessEventIds as $eventId) {
            if ($event = $em->getRepository(AccessEvent::class)->findOneBy(['eventId' => $eventId])) {
                $em->remove($event);
            }
        }
        foreach ($this->userIds as $userId) {
            if ($user = $em->getRepository(User::class)->find($userId)) {
                $em->remove($user); // cascades to its AccessCard and Attendee rows
            }
        }
        if ($this->eventId !== null && ($event = $em->getRepository(Event::class)->find($this->eventId))) {
            $em->remove($event);
        }
        $em->flush();

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function randomUid(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }

    /** @return array{user: User, card: AccessCard, uid: string} */
    private function createUserWithCard(EntityManagerInterface $em, string $label, string $uid): array
    {
        $user = new User();
        $user->setEmail(sprintf('phpunit-exit-%s-%s@example.test', $label, bin2hex(random_bytes(4))));
        $user->setFirstName('Exit');
        $user->setLastName(ucfirst($label));
        $user->setPassword('unused — never logged into');
        $em->persist($user);

        $card = new AccessCard($user, $uid, null);
        $em->persist($card);
        $em->flush();

        $this->userIds[] = $user->getId();

        return ['user' => $user, 'card' => $card, 'uid' => $uid];
    }

    private function recordTap(EntityManagerInterface $em, string $uid, \DateTimeImmutable $at): void
    {
        $eventId = $this->uuid();
        $event = new AccessEvent($eventId, AccessEvent::TYPE_STANDING_ACCESS);
        $event->setCard($uid, null);
        $event->advanceStage(AccessEvent::STAGE_AUTHORIZED, $at);
        $em->persist($event);
        $this->accessEventIds[] = $eventId;
    }

    private function createCheckedInAttendee(EntityManagerInterface $em, User $user, \DateTimeImmutable $checkedInAt): Attendee
    {
        if ($this->eventId === null) {
            $event = new Event();
            $event->setTitle('PHPUnit exit-reader session');
            $event->setLocation('Test wall');
            $event->setDate(new \DateTimeImmutable('today'));
            $event->setTimeFrom('00:00');
            $event->setTimeTo('23:59');
            $event->setIsSelfAccess(true);
            $em->persist($event);
            $em->flush();
            $this->eventId = $event->getId();
        }

        $attendee = new Attendee();
        $attendee->setEvent($em->getRepository(Event::class)->find($this->eventId));
        $attendee->setUser($user);
        $attendee->setStatus(Attendee::STATUS_CONFIRMED);
        $attendee->setCheckedInAt($checkedInAt);
        $attendee->setCheckedInMethod(Attendee::CHECKED_IN_DOOR_CARD);
        $em->persist($attendee);

        return $attendee;
    }

    private function getCredentials(KernelBrowser $client): array
    {
        $client->request('GET', '/v1/doors/1/credentials', server: $this->authHeader());
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true);
    }

    private function postMemberExit(KernelBrowser $client, string $uid, string $stage, \DateTimeImmutable $authorizedAt): string
    {
        $eventId = $this->uuid();
        $this->accessEventIds[] = $eventId;

        $client->request('POST', '/v1/doors/1/events', server: $this->authHeader() + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'events' => [[
                'event_id'      => $eventId,
                'type'          => AccessEvent::TYPE_MEMBER_EXIT,
                'card_uid'      => $uid,
                'stage'         => $stage,
                'authorized_at' => $authorizedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ]],
        ]));

        self::assertResponseStatusCodeSame(202);
        self::assertContains($eventId, json_decode($client->getResponse()->getContent(), true)['accepted']);

        return $eventId;
    }

    private function authHeader(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . ($_ENV['DOOR_API_KEY'] ?? 'change-me')];
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
