<?php

namespace App\Controller\Web;

use App\Entity\AccessEvent;
use App\Entity\Attendee;
use App\Entity\User;
use App\Repository\AccessEventRepository;
use App\Repository\AttendeeRepository;
use App\Repository\UserRepository;
use App\Service\DoorAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A self-contained "virtual keypad + door" that mimics the physical door controller, for UAT with
 * non-staff volunteers — see door-access-spec.md and door-access-firmware-spec.md § Door position
 * sensing. Gated by a shared secret in the URL (same pattern as WebhookController) rather than a
 * staff login, so it can be handed out as a link without creating real accounts for external
 * testers, and revoked by rotating one env var.
 *
 * Deliberately calls DoorAccessService directly rather than going over HTTP to the real
 * /v1/doors/... endpoints: that's the same production logic the physical door drives, without
 * ever putting the door's own DOOR_API_KEY in front of a tester's browser. A successful PIN entry
 * here is a real access attempt — it consumes the attendee's actual booking PIN (or logs a real
 * keyholder disarm) the same as a genuine tap, and writes real AccessEvent rows.
 *
 * event_id is generated client-side (crypto.randomUUID()) exactly as the real firmware does, and
 * carried through attempt → door/open → door/close — the same idempotency key the real device
 * would use. An "unexpected open" is simply a door/open call whose event_id was never authorized
 * first, which is exactly how the real firmware's UNEXPECTED_OPEN state comes about too.
 */
#[Route('/door-sim/{secret}')]
class DoorSimController extends AbstractController
{
    /** How long a door can stay open before the sim treats it as a propped-door alarm — matches the firmware spec's placeholder threshold. */
    private const ALARM_THRESHOLD_SECONDS = 20;

    public function __construct(
        private readonly string $doorSimSecret,
        private readonly DoorAccessService $doorAccessService,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly AccessEventRepository $accessEventRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_door_sim', methods: ['GET'])]
    public function index(Request $request, string $secret): Response
    {
        $this->assertSecret($secret);

        return $this->render('door_sim/index.html.twig', [
            'secret'          => $secret,
            'debug'           => $request->query->getBoolean('debug'),
            'alarmThresholdS' => self::ALARM_THRESHOLD_SECONDS,
        ]);
    }

    /**
     * Mimics the firmware's keypad-match step: checked against the attendee credential cache
     * first, then the keyholder cache, generic "denied" either way per the spec (no reason leaked
     * to the UI) — unless the caller opted into ?debug. A grant only records the `authorized`
     * stage; the door itself (open/close) is simulated separately below, exactly as the real door
     * doesn't complete an access until its sensor sees the door close again.
     */
    #[Route('/attempt', name: 'app_door_sim_attempt', methods: ['POST'])]
    public function attempt(Request $request, string $secret): JsonResponse
    {
        $this->assertSecret($secret);

        $payload = json_decode($request->getContent(), true);
        $pin     = is_array($payload) ? trim((string) ($payload['pin'] ?? '')) : '';
        $eventId = is_array($payload) ? trim((string) ($payload['eventId'] ?? '')) : '';
        $debug   = is_array($payload) && ($payload['debug'] ?? false);
        $now     = new \DateTimeImmutable();

        if ($eventId === '' || !preg_match('/^\d{6}$/', $pin)) {
            return $this->denied($now, $pin, $eventId, null, 'not_found', $debug);
        }

        // Attendee credential cache first (the common case), then the much smaller keyholder
        // cache — same order the firmware checks in, though correctness doesn't depend on it
        // since the two PIN pools are kept disjoint (see § PIN lifecycle).
        $credentials = $this->doorAccessService->findCredentialsForDoor(DoorAccessService::SUPPORTED_DOOR_ID, $now);
        foreach ($credentials as $credential) {
            if ($credential['pin'] === $pin && $now >= $credential['valid_from'] && $now <= $credential['valid_until']) {
                $attendee = $this->attendeeRepository->find($credential['credential_id']);
                if ($attendee instanceof Attendee) {
                    $this->doorAccessService->applyAttendeeAccessEvent($eventId, AccessEvent::STAGE_AUTHORIZED, $attendee, $now);
                    $this->em->flush();

                    return new JsonResponse(array_merge(
                        ['result' => 'granted', 'accessType' => 'attendee', 'relayPulsed' => true, 'name' => $attendee->getUser()->getFirstName()],
                        $debug ? ['debug' => $this->buildDebugInfo($now, $pin)] : [],
                    ));
                }
            }
        }

        foreach ($this->doorAccessService->findKeyholdersForDoor(DoorAccessService::SUPPORTED_DOOR_ID) as $keyholder) {
            if ($keyholder['pin'] === $pin) {
                $user = $this->userRepository->find($keyholder['user_id']);
                if ($user) {
                    $this->doorAccessService->applyKeyholderAccessEvent($eventId, AccessEvent::STAGE_AUTHORIZED, $user, $now);
                    $this->em->flush();

                    return new JsonResponse(array_merge(
                        ['result' => 'granted', 'accessType' => 'keyholder', 'relayPulsed' => false, 'name' => $user->getFirstName()],
                        $debug ? ['debug' => $this->buildDebugInfo($now, $pin)] : [],
                    ));
                }
            }
        }

        $attendeeForReason = $this->attendeeRepository->findByPinAnyStatus($pin)[0] ?? null;
        $reason = $attendeeForReason instanceof Attendee
            ? ($attendeeForReason->getPinStatus() === Attendee::PIN_STATUS_USED ? 'already_used' : 'expired')
            : 'not_found';

        return $this->denied($now, $pin, $eventId, $attendeeForReason, $reason, $debug);
    }

    /**
     * Simulates the door sensor observing closed→open. If {eventId} already refers to an
     * authorized attempt, advances it to door_open. If it's never been seen before, this IS an
     * unexpected_open — exactly how the real firmware's UNEXPECTED_OPEN state comes about: the
     * door opened with nothing having authorized it first.
     */
    #[Route('/door/open', name: 'app_door_sim_door_open', methods: ['POST'])]
    public function doorOpen(Request $request, string $secret): JsonResponse
    {
        $this->assertSecret($secret);

        $payload = json_decode($request->getContent(), true);
        $eventId = is_array($payload) ? trim((string) ($payload['eventId'] ?? '')) : '';
        if ($eventId === '') {
            return new JsonResponse(['error' => 'eventId is required.'], 422);
        }

        $now = new \DateTimeImmutable();
        $existing = $this->accessEventRepository->findOneByEventId($eventId);

        if ($existing === null) {
            $this->doorAccessService->applyUnexpectedOpenEvent($eventId, AccessEvent::STAGE_DOOR_OPEN, $now);
        } else {
            $this->advanceExisting($existing, AccessEvent::STAGE_DOOR_OPEN, $now);
        }

        $this->em->flush();

        return new JsonResponse(['stage' => 'door_open', 'openedAt' => $now->format('Y-m-d\TH:i:s\Z')]);
    }

    /** Simulates the door sensor observing open→closed — completes the access, whichever type it was. */
    #[Route('/door/close', name: 'app_door_sim_door_close', methods: ['POST'])]
    public function doorClose(Request $request, string $secret): JsonResponse
    {
        $this->assertSecret($secret);

        $payload = json_decode($request->getContent(), true);
        $eventId = is_array($payload) ? trim((string) ($payload['eventId'] ?? '')) : '';
        $event   = $eventId !== '' ? $this->accessEventRepository->findOneByEventId($eventId) : null;

        if (!$event) {
            return new JsonResponse(['error' => 'Unknown eventId — was door/open called first?'], 422);
        }

        $now = new \DateTimeImmutable();
        $this->advanceExisting($event, AccessEvent::STAGE_DOOR_CLOSED, $now);
        $this->em->flush();

        $openedAt = $event->getDoorOpenAt();
        $durationS = $openedAt ? $now->getTimestamp() - $openedAt->getTimestamp() : null;

        return new JsonResponse([
            'stage'      => 'door_closed',
            'closedAt'   => $now->format('Y-m-d\TH:i:s\Z'),
            'durationS'  => $durationS,
            'wasAlarmed' => $durationS !== null && $durationS > self::ALARM_THRESHOLD_SECONDS,
        ]);
    }

    private function advanceExisting(AccessEvent $event, string $stage, \DateTimeImmutable $now): void
    {
        match ($event->getType()) {
            AccessEvent::TYPE_ATTENDEE_ACCESS  => $this->doorAccessService->applyAttendeeAccessEvent($event->getEventId(), $stage, $event->getAttendee(), $now),
            AccessEvent::TYPE_KEYHOLDER_ACCESS => $this->doorAccessService->applyKeyholderAccessEvent($event->getEventId(), $stage, $event->getKeyholderUser(), $now),
            AccessEvent::TYPE_UNEXPECTED_OPEN  => $this->doorAccessService->applyUnexpectedOpenEvent($event->getEventId(), $stage, $now),
            default => null,
        };
    }

    private function denied(\DateTimeImmutable $now, string $pin, string $eventId, ?Attendee $attendee, string $reason, bool $debug): JsonResponse
    {
        if ($eventId !== '') {
            $this->doorAccessService->applyAccessDenied($eventId, $attendee, $reason, $now);
            $this->em->flush();
        }

        return new JsonResponse(array_merge(
            ['result' => 'denied'],
            $debug ? ['debug' => $this->buildDebugInfo($now, $pin)] : [],
        ));
    }

    /**
     * @return array{server_time_utc: string, server_php_timezone: string, candidates: array, pin_lookup: array}
     */
    private function buildDebugInfo(\DateTimeImmutable $now, string $pin): array
    {
        $candidates = array_map(
            static fn (array $c) => [
                'credential_id' => $c['credential_id'],
                'pin'           => $c['pin'],
                'valid_from'    => $c['valid_from']->format('Y-m-d\TH:i:s\Z'),
                'valid_until'   => $c['valid_until']->format('Y-m-d\TH:i:s\Z'),
                'status'        => $c['status'],
                'matches_pin'   => $c['pin'] === $pin,
                'now_in_window' => $now >= $c['valid_from'] && $now <= $c['valid_until'],
            ],
            $this->doorAccessService->findCredentialsForDoor(DoorAccessService::SUPPORTED_DOOR_ID, $now),
        );

        $keyholders = array_map(
            static fn (array $k) => ['user_id' => $k['user_id'], 'pin' => $k['pin'], 'matches_pin' => $k['pin'] === $pin],
            $this->doorAccessService->findKeyholdersForDoor(DoorAccessService::SUPPORTED_DOOR_ID),
        );

        $pinLookup = $pin !== '' ? array_map(function (Attendee $attendee) {
            $event = $attendee->getEvent();

            return [
                'attendee_id'       => $attendee->getId(),
                'user'              => $attendee->getUser()->getDisplayName(),
                'attendee_status'   => $attendee->getStatus(),
                'pin_status'        => $attendee->getPinStatus(),
                'event_title'       => $event->getTitle(),
                'event_is_self_access' => $event->isSelfAccess(),
                'event_date'        => $event->getDate()->format('Y-m-d'),
                'occurrence_date'   => $attendee->getOccurrenceDate()?->format('Y-m-d'),
                'event_time_from'   => $event->getTimeFrom(),
                'event_time_to'     => $event->getTimeTo(),
                'valid_from_utc'    => $this->doorAccessService->computeValidFrom($attendee)->format('Y-m-d\TH:i:s\Z'),
                'valid_until_utc'   => $this->doorAccessService->computeValidUntil($attendee)->format('Y-m-d\TH:i:s\Z'),
            ];
        }, $this->attendeeRepository->findByPinAnyStatus($pin)) : [];

        return [
            'server_time_utc'      => $now->format('Y-m-d\TH:i:s\Z'),
            'server_php_timezone'  => date_default_timezone_get(),
            'note'                 => 'event_time_from/to and event_date are stored as plain values with no timezone conversion — the server reads them as being in server_php_timezone (UTC), not necessarily the time an admin meant in local UK clock time (BST is UTC+1).',
            'candidates'           => $candidates,
            'keyholders'           => $keyholders,
            'pin_lookup'           => $pinLookup,
        ];
    }

    private function assertSecret(string $secret): void
    {
        if (!hash_equals($this->doorSimSecret, $secret)) {
            throw $this->createNotFoundException();
        }
    }
}
