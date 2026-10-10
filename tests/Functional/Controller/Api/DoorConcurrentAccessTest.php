<?php

namespace App\Tests\Functional\Controller\Api;

use App\Entity\AccessCard;
use App\Entity\AccessEvent;
use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Several people through one door opening (door-access-spec.md § Concurrent access): member A taps
 * in and the door opens; member B taps while it's still open; member C taps out on the inside reader
 * in the same opening. The door reports each tap as its own event, all sharing the one door_open_at/
 * door_closed_at — and each booking must be checked in/out on its own.
 */
class DoorConcurrentAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?int $eventId = null;
    /** @var int[] */
    private array $userIds = [];
    /** @var string[] */
    private array $accessEventIds = [];

    public function testEveryTapDuringOneDoorOpeningIsRecordedForItsOwnBooking(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $event = new Event();
        $event->setTitle('PHPUnit concurrent self-access session');
        $event->setLocation('Test wall');
        $event->setDate(new \DateTimeImmutable('today'));
        $event->setTimeFrom('00:00');
        $event->setTimeTo('23:59');
        $event->setIsSelfAccess(true);
        $em->persist($event);
        $em->flush();
        $this->eventId = $event->getId();

        [$a, $aUid] = $this->bookedMember($em, $event, 'a');
        [$b, $bUid] = $this->bookedMember($em, $event, 'b');
        [$c, $cUid] = $this->bookedMember($em, $event, 'c');
        $c->setCheckedInAt($now->modify('-1 hour'));
        $c->setCheckedInMethod(Attendee::CHECKED_IN_DOOR_CARD);
        $em->flush();

        // Whole seconds, UTC — as the door sends them. One physical opening: A taps, the door opens,
        // B and C tap while it's open, then it closes once.
        $aTap   = $this->secondsAgo($now, 30);
        $opened = $this->secondsAgo($now, 28);
        $bTap   = $this->secondsAgo($now, 26);
        $cTap   = $this->secondsAgo($now, 25);
        $closed = $this->secondsAgo($now, 20);

        $aEvent = $this->uuid();
        $bEvent = $this->uuid();
        $cEvent = $this->uuid();
        $entry = fn (string $eventId, Attendee $attendee, string $uid, \DateTimeImmutable $tap) => [
            'event_id'      => $eventId,
            'type'          => AccessEvent::TYPE_ATTENDEE_ACCESS,
            'credential_id' => $attendee->getId(),
            'channel'       => 'card',
            'card_uid'      => $uid,
            'authorized_at' => $this->iso($tap),
        ];
        $exit = [
            'event_id'      => $cEvent,
            'type'          => AccessEvent::TYPE_MEMBER_EXIT,
            'card_uid'      => $cUid,
            'authorized_at' => $this->iso($cTap),
        ];
        $openedFields = ['door_open_at' => $this->iso($opened)];
        $closedFields = $openedFields + ['door_closed_at' => $this->iso($closed)];

        // Sent in the order the door would queue them: each tap as it happens, then every member of
        // the opening progressed to door_open / door_closed together.
        $this->postEvents([
            $entry($aEvent, $a, $aUid, $aTap) + ['stage' => AccessEvent::STAGE_AUTHORIZED],
            $entry($aEvent, $a, $aUid, $aTap) + ['stage' => AccessEvent::STAGE_DOOR_OPEN] + $openedFields,
            $entry($bEvent, $b, $bUid, $bTap) + ['stage' => AccessEvent::STAGE_AUTHORIZED],
            $entry($bEvent, $b, $bUid, $bTap) + ['stage' => AccessEvent::STAGE_DOOR_OPEN] + $openedFields,
            $exit + ['stage' => AccessEvent::STAGE_AUTHORIZED],
            $exit + ['stage' => AccessEvent::STAGE_DOOR_OPEN] + $openedFields,
        ]);
        $this->postEvents([
            $entry($aEvent, $a, $aUid, $aTap) + ['stage' => AccessEvent::STAGE_DOOR_CLOSED] + $closedFields,
            $entry($bEvent, $b, $bUid, $bTap) + ['stage' => AccessEvent::STAGE_DOOR_CLOSED] + $closedFields,
            $exit + ['stage' => AccessEvent::STAGE_DOOR_CLOSED] + $closedFields,
        ]);

        $em->clear();
        $repo = $em->getRepository(Attendee::class);

        foreach ([$a, $b] as $entrant) {
            $attendee = $repo->find($entrant->getId());
            self::assertSame($closed->format('Y-m-d H:i:s'), $attendee->getCheckedInAt()?->format('Y-m-d H:i:s'), 'Each entrant is checked in when the shared opening closes.');
            self::assertSame(Attendee::CHECKED_IN_DOOR_CARD, $attendee->getCheckedInMethod());
            self::assertNull($attendee->getCheckedOutAt());
        }

        $leaver = $repo->find($c->getId());
        self::assertSame($cTap->format('Y-m-d H:i:s'), $leaver->getCheckedOutAt()?->format('Y-m-d H:i:s'), 'The exit in the same opening checks its member out at their own tap.');

        foreach ([[$aEvent, $a], [$bEvent, $b], [$cEvent, $c]] as [$eventId, $attendee]) {
            $row = $em->getRepository(AccessEvent::class)->findOneBy(['eventId' => $eventId]);
            self::assertSame(AccessEvent::STAGE_DOOR_CLOSED, $row->getStage());
            self::assertSame($attendee->getId(), $row->getAttendee()?->getId(), 'Each tap keeps its own row, linked to its own booking.');
            self::assertSame($opened->format('Y-m-d H:i:s'), $row->getDoorOpenAt()?->format('Y-m-d H:i:s'));
            self::assertSame($closed->format('Y-m-d H:i:s'), $row->getDoorClosedAt()?->format('Y-m-d H:i:s'));
        }
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();

        foreach ($this->accessEventIds as $eventId) {
            if ($event = $em->getRepository(AccessEvent::class)->findOneBy(['eventId' => $eventId])) {
                $em->remove($event);
            }
        }
        $em->flush();

        foreach ($this->userIds as $userId) {
            if ($user = $em->getRepository(User::class)->find($userId)) {
                $em->remove($user); // cascades to its AccessCard and Attendee rows
            }
        }
        $em->flush();

        if ($this->eventId !== null && ($event = $em->getRepository(Event::class)->find($this->eventId))) {
            $em->remove($event);
            $em->flush();
        }

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return array{0: Attendee, 1: string} */
    private function bookedMember(EntityManagerInterface $em, Event $event, string $label): array
    {
        $user = new User();
        $user->setEmail(sprintf('phpunit-concurrent-%s-%s@example.test', $label, bin2hex(random_bytes(4))));
        $user->setFirstName('Concurrent');
        $user->setLastName(strtoupper($label));
        $user->setPassword('unused — never logged into');
        $em->persist($user);

        $uid = strtoupper(bin2hex(random_bytes(4)));
        $em->persist(new AccessCard($user, $uid, null));

        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setStatus(Attendee::STATUS_CONFIRMED);
        $em->persist($attendee);
        $em->flush();

        $this->userIds[] = $user->getId();

        return [$attendee, $uid];
    }

    private function postEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->accessEventIds[] = $event['event_id'];
        }

        $this->client->request('POST', '/v1/doors/1/events', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($_ENV['DOOR_API_KEY'] ?? 'change-me'),
            'CONTENT_TYPE'       => 'application/json',
        ], content: json_encode(['events' => $events]));

        self::assertResponseStatusCodeSame(202);
    }

    private function secondsAgo(\DateTimeImmutable $now, int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . ($now->getTimestamp() - $seconds));
    }

    private function iso(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
