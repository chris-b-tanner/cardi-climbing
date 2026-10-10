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
 * Which booking an exit-reader tap checks out (DoorAccessService::findSessionForExit(),
 * door-access-spec.md § Exit reader), driven through the real door API. Every scenario is the same
 * member on the same day with two bookings: a staffed daytime session (not self-access, 09:00–12:00)
 * they were checked into manually at reception at 09:05 and never tapped out of, and a separate
 * evening self-access session (18:00–20:00). The evening's taps must only ever touch the evening
 * booking; the day booking only closes on a genuine overstay exit.
 */
class DoorExitSessionMatchingTest extends WebTestCase
{
    private KernelBrowser $client;
    private \DateTimeImmutable $date;
    private ?int $userId = null;
    /** @var int[] */
    private array $eventIds = [];
    /** @var string[] */
    private array $accessEventIds = [];

    private Attendee $day;
    private Attendee $evening;
    private string $cardUid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->date = new \DateTimeImmutable('tomorrow');
        $em = $this->em();

        $user = new User();
        $user->setEmail(sprintf('phpunit-exit-session-%s@example.test', bin2hex(random_bytes(4))));
        $user->setFirstName('Exit');
        $user->setLastName('Session');
        $user->setPassword('unused — never logged into');
        $em->persist($user);

        $this->cardUid = strtoupper(bin2hex(random_bytes(4)));
        $em->persist(new AccessCard($user, $this->cardUid, null));

        $this->day = $this->createBooking($em, $user, $this->createEvent($em, 'PHPUnit staffed day session', '09:00', '12:00', false));
        $this->day->setCheckedInAt($this->at('09:05'));
        $this->day->setCheckedInMethod(Attendee::CHECKED_IN_MANUAL);

        $this->evening = $this->createBooking($em, $user, $this->createEvent($em, 'PHPUnit self-access evening', '18:00', '20:00', true));

        $em->flush();
        $this->userId = $user->getId();
    }

    public function testEveningEntryAndExitOnlyTouchTheEveningBooking(): void
    {
        $this->enter('18:10');
        $this->exit('19:50');

        $this->assertDay(checkedOut: null);
        $this->assertEvening(checkedIn: '18:10', checkedOut: '19:50');
    }

    public function testReEntryDuringTheEveningMovesTheCheckoutToTheLastExit(): void
    {
        $this->enter('18:10');
        $this->exit('18:30');
        $this->enter('18:40');
        $this->exit('19:55');

        $this->assertDay(checkedOut: null);
        $this->assertEvening(checkedIn: '18:10', checkedOut: '19:55');
    }

    public function testExitAfterAClosedEveningSessionDoesNotReachBackToTheDayBooking(): void
    {
        $this->enter('18:10');
        $this->exit('19:00');
        $this->enter('19:10');
        $this->exit('21:00'); // after the evening window — the accepted gap: not recorded anywhere

        $this->assertDay(checkedOut: null);
        $this->assertEvening(checkedIn: '18:10', checkedOut: '19:00');
    }

    public function testExitDuringAnEveningSessionTheyWereNeverCheckedIntoClosesNothing(): void
    {
        // e.g. they followed someone else in without tapping
        $this->exit('19:30');

        $this->assertDay(checkedOut: null);
        $this->assertEvening(checkedIn: null, checkedOut: null);
    }

    public function testEveningOverstayIsCheckedOutAtTheTapTime(): void
    {
        $this->enter('18:10');
        $this->exit('21:30');

        $this->assertDay(checkedOut: null);
        $this->assertEvening(checkedIn: '18:10', checkedOut: '21:30');
    }

    public function testDaytimeOverstayIsCheckedOutAtTheTapTime(): void
    {
        $this->exit('13:30');

        $this->assertDay(checkedOut: '13:30');
        $this->assertEvening(checkedIn: null, checkedOut: null);
    }

    public function testDayAndEveningSessionsAreEachCheckedOutSeparately(): void
    {
        $this->exit('11:00');
        $this->enter('18:10');
        $this->exit('19:50');

        $this->assertDay(checkedOut: '11:00');
        $this->assertEvening(checkedIn: '18:10', checkedOut: '19:50');
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

        if ($this->userId !== null && ($user = $em->getRepository(User::class)->find($this->userId))) {
            $em->remove($user); // cascades to its AccessCard and Attendee rows
            $em->flush();
        }
        foreach ($this->eventIds as $eventId) {
            if ($event = $em->getRepository(Event::class)->find($eventId)) {
                $em->remove($event);
            }
        }
        $em->flush();

        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function createEvent(EntityManagerInterface $em, string $title, string $from, string $to, bool $selfAccess): Event
    {
        $event = new Event();
        $event->setTitle($title);
        $event->setLocation('Test wall');
        $event->setDate($this->date);
        $event->setTimeFrom($from);
        $event->setTimeTo($to);
        $event->setIsSelfAccess($selfAccess);
        $em->persist($event);
        $em->flush();
        $this->eventIds[] = $event->getId();

        return $event;
    }

    private function createBooking(EntityManagerInterface $em, User $user, Event $event): Attendee
    {
        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setStatus(Attendee::STATUS_CONFIRMED);
        $em->persist($attendee);

        return $attendee;
    }

    /** {hm} UK wall-clock time on the test date, as the UTC instant the door would report. */
    private function at(string $hm): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($this->date->format('Y-m-d') . ' ' . $hm, new \DateTimeZone('Europe/London')))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** An evening card entry at {hm} — authorized, then the door opening and closing in the same second. */
    private function enter(string $hm): void
    {
        $eventId = $this->uuid();
        $at = $this->iso($hm);
        $base = [
            'event_id'      => $eventId,
            'type'          => AccessEvent::TYPE_ATTENDEE_ACCESS,
            'credential_id' => $this->evening->getId(),
            'channel'       => 'card',
            'card_uid'      => $this->cardUid,
            'authorized_at' => $at,
        ];

        $this->postEvents($eventId, [
            $base + ['stage' => AccessEvent::STAGE_AUTHORIZED],
            $base + ['stage' => AccessEvent::STAGE_DOOR_OPEN, 'door_open_at' => $at],
            $base + ['stage' => AccessEvent::STAGE_DOOR_CLOSED, 'door_open_at' => $at, 'door_closed_at' => $at],
        ]);
    }

    private function exit(string $hm): void
    {
        $eventId = $this->uuid();

        $this->postEvents($eventId, [[
            'event_id'      => $eventId,
            'type'          => AccessEvent::TYPE_MEMBER_EXIT,
            'card_uid'      => $this->cardUid,
            'stage'         => AccessEvent::STAGE_AUTHORIZED,
            'authorized_at' => $this->iso($hm),
        ]]);
    }

    private function postEvents(string $eventId, array $events): void
    {
        $this->accessEventIds[] = $eventId;

        $this->client->request('POST', '/v1/doors/1/events', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($_ENV['DOOR_API_KEY'] ?? 'change-me'),
            'CONTENT_TYPE'       => 'application/json',
        ], content: json_encode(['events' => $events]));

        self::assertResponseStatusCodeSame(202);
    }

    private function assertDay(?string $checkedOut): void
    {
        $day = $this->reload($this->day);
        self::assertSame('09:05', $this->hm($day->getCheckedInAt()), 'The day booking\'s manual check-in should never change.');
        self::assertSame($checkedOut, $this->hm($day->getCheckedOutAt()), 'Day booking checkout');
    }

    private function assertEvening(?string $checkedIn, ?string $checkedOut): void
    {
        $evening = $this->reload($this->evening);
        self::assertSame($checkedIn, $this->hm($evening->getCheckedInAt()), 'Evening booking check-in');
        self::assertSame($checkedOut, $this->hm($evening->getCheckedOutAt()), 'Evening booking checkout');
    }

    private function reload(Attendee $attendee): Attendee
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Attendee::class)->find($attendee->getId());
    }

    /** DATETIME columns carry no zone — the stored value is the UTC wall-clock the door sent; shown back as UK time. */
    private function hm(?\DateTimeImmutable $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        return (new \DateTimeImmutable($stored->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/London'))
            ->format('H:i');
    }

    private function iso(string $hm): string
    {
        return $this->at($hm)->format('Y-m-d\TH:i:s\Z');
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
