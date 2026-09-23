<?php

namespace App\Tests\Functional;

use App\Entity\AccessCard;
use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A locked card must never appear in the door's credential sync — that's the entire point of
 * locking one rather than deleting it (see card-setup.md's "Why two real tables"): the door stops
 * trusting it immediately, without anyone needing to remember to also touch the credential sync,
 * because DoorAccessService::findCredentialsForDoor() sources `card_uid` from
 * AccessCardRepository::findActiveForUser() (status=active only), not a raw column read. This
 * test locks that behaviour in against a real HTTP call to /v1/doors/1/credentials.
 */
class DoorCredentialsLockedCardTest extends WebTestCase
{
    private ?int $userId = null;
    private ?int $eventId = null;
    private ?int $attendeeId = null;

    public function testLockedCardIsOmittedFromDoorCredentials(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/London'));

        $user = new User();
        $user->setEmail(sprintf('phpunit-locked-card-%s@example.test', bin2hex(random_bytes(4))));
        $user->setFirstName('Locked');
        $user->setLastName('Cardholder');
        $user->setPassword('unused — never logged into');
        $em->persist($user);

        $event = new Event();
        $event->setTitle('PHPUnit self-access session');
        $event->setLocation('Test wall');
        $event->setDate(new \DateTimeImmutable($now->format('Y-m-d')));
        $event->setTimeFrom($now->modify('-30 minutes')->format('H:i'));
        $event->setTimeTo($now->modify('+30 minutes')->format('H:i'));
        $event->setIsSelfAccess(true);
        $em->persist($event);

        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setStatus(Attendee::STATUS_CONFIRMED);
        $attendee->setPin('654321');
        $attendee->setPinStatus(Attendee::PIN_STATUS_ACTIVE);
        $em->persist($attendee);
        $em->flush();

        $this->userId = $user->getId();
        $this->eventId = $event->getId();
        $this->attendeeId = $attendee->getId();

        $card = new AccessCard($user, 'AABBCCDD', null);
        $card->lock($user); // who locks it doesn't matter for this test, just that it's locked
        $em->persist($card);
        $em->flush();

        $client->request(
            'GET',
            '/v1/doors/1/credentials',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . ($_ENV['DOOR_API_KEY'] ?? 'change-me')],
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode($client->getResponse()->getContent(), true);
        $credential = null;
        foreach ($payload['credentials'] as $entry) {
            if ($entry['credential_id'] === $this->attendeeId) {
                $credential = $entry;
                break;
            }
        }

        self::assertNotNull($credential, 'Expected this booking\'s PIN credential to be present at all.');
        self::assertArrayNotHasKey('card_uid', $credential, 'A locked card must not be exposed to the door as a working credential.');
    }

    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        if ($this->userId !== null) {
            $user = $em->getRepository(User::class)->find($this->userId);
            if ($user) {
                $em->remove($user); // cascades to its AccessCard and Attendee rows
            }
        }

        if ($this->eventId !== null) {
            $event = $em->getRepository(Event::class)->find($this->eventId);
            if ($event) {
                $em->remove($event);
            }
        }

        $em->flush();

        parent::tearDown();
    }
}
