<?php

namespace App\Controller\Api;

use App\Entity\AccessEvent;
use App\Entity\Attendee;
use App\Entity\DoorLog;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\DoorLogRepository;
use App\Repository\UserRepository;
use App\Service\DoorAccessService;
use App\Service\DoorAlertMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-side API the self-access door controller talks to — see door-access-spec.md. Every route
 * here authenticates the physical door device itself (a shared bearer token), not a logged-in user.
 */
#[Route('/v1/doors/{doorId}')]
class DoorController extends AbstractController
{
    public function __construct(
        private readonly string $doorApiKey,
        private readonly int $doorMinFirmwareVersion,
        private readonly DoorAccessService $doorAccessService,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly UserRepository $userRepository,
        private readonly DoorLogRepository $doorLogRepository,
        private readonly DoorAlertMailer $doorAlertMailer,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Per-door cooldown between alert emails — a stuck sensor generating repeat alert-worthy entries shouldn't flood an inbox (see § Server-side alerting). */
    private const ALERT_COOLDOWN_MINUTES = 10;

    /** Active + near-future (next 2h) PIN (and, where registered, card) credentials, plus keyholder disarm PINs, for this door — an authoritative full replace of the device's local cache on every poll. */
    #[Route('/credentials', name: 'app_api_door_credentials', requirements: ['doorId' => '\d+'], methods: ['GET'])]
    public function credentials(Request $request, int $doorId): Response
    {
        if ($denied = $this->checkDeviceAuth($request)) {
            return $denied;
        }

        if ($doorId !== DoorAccessService::SUPPORTED_DOOR_ID) {
            return new JsonResponse(['error' => 'Unknown door.'], 404);
        }

        $now = new \DateTimeImmutable();

        $credentials = array_map(
            static function (array $c) {
                $entry = [
                    'credential_id' => $c['credential_id'],
                    'pin'           => $c['pin'],
                    'valid_from'    => $c['valid_from']->format('Y-m-d\TH:i:s\Z'),
                    'valid_until'   => $c['valid_until']->format('Y-m-d\TH:i:s\Z'),
                    'status'        => $c['status'],
                ];

                // Omitted rather than null — see door-access-spec.md § Card-based entry.
                if ($c['card_uid'] !== null) {
                    $entry['card_uid'] = $c['card_uid'];
                }

                return $entry;
            },
            $this->doorAccessService->findCredentialsForDoor($doorId, $now),
        );

        $keyholders = $this->doorAccessService->findKeyholdersForDoor($doorId);

        $etag = '"' . md5(json_encode([$credentials, $keyholders])) . '"';

        // A 304 has no body by definition, so min_firmware_version can't ride in the JSON here —
        // carried as a header instead, so an OTA update is never missed just because credentials
        // happened not to change on a given poll (the common case).
        $firmwareHeaders = ['X-Min-Firmware-Version' => (string) $this->doorMinFirmwareVersion];

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response(null, 304, ['ETag' => $etag] + $firmwareHeaders);
        }

        return new JsonResponse([
            'server_time'          => $now->format('Y-m-d\TH:i:s\Z'),
            'min_firmware_version' => $this->doorMinFirmwareVersion,
            'credentials'          => $credentials,
            'keyholders'           => $keyholders,
        ], 200, ['ETag' => $etag] + $firmwareHeaders);
    }

    /**
     * Batched, idempotent door events — attendee_access/keyholder_access/unexpected_open arrive in
     * up to three POSTs per event_id (one per stage), upserted onto one AccessEvent row each;
     * access_denied is a single-stage write. See door-access-spec.md § Access event log.
     */
    #[Route('/events', name: 'app_api_door_events', requirements: ['doorId' => '\d+'], methods: ['POST'])]
    public function events(Request $request, int $doorId): Response
    {
        if ($denied = $this->checkDeviceAuth($request)) {
            return $denied;
        }

        if ($doorId !== DoorAccessService::SUPPORTED_DOOR_ID) {
            return new JsonResponse(['error' => 'Unknown door.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $events  = is_array($payload['events'] ?? null) ? $payload['events'] : [];

        $accepted = [];

        foreach ($events as $event) {
            $eventId = $event['event_id'] ?? null;
            $type    = $event['type'] ?? null;

            if (!is_string($eventId) || $eventId === '') {
                continue;
            }

            match ($type) {
                AccessEvent::TYPE_ATTENDEE_ACCESS  => $this->applyAttendeeAccess($eventId, $event),
                AccessEvent::TYPE_KEYHOLDER_ACCESS => $this->applyKeyholderAccess($eventId, $event),
                AccessEvent::TYPE_UNEXPECTED_OPEN  => $this->applyUnexpectedOpen($eventId, $event),
                AccessEvent::TYPE_ACCESS_DENIED    => $this->applyAccessDenied($eventId, $doorId, $event),
                default => null,
            };

            // Every recognised event_id is acknowledged regardless of whether it resolved to
            // anything — the device just needs this to stop retrying it.
            $accepted[] = $eventId;
        }

        $this->em->flush();

        return new JsonResponse([
            'accepted'             => $accepted,
            'min_firmware_version' => $this->doorMinFirmwareVersion,
        ], 202);
    }

    /**
     * Batched, idempotent diagnostic log entries — see door-access-firmware-spec.md § Diagnostic
     * log for the full category/level/reason taxonomy. Unlike /events, a log entry never mutates
     * a booking/attendee row — it's purely for remote visibility once the device has no serial
     * console attached. Idempotent on log_id: a retried upload of one already stored is a no-op,
     * not a duplicate row.
     */
    #[Route('/logs', name: 'app_api_door_logs', requirements: ['doorId' => '\d+'], methods: ['POST'])]
    public function logs(Request $request, int $doorId): Response
    {
        if ($denied = $this->checkDeviceAuth($request)) {
            return $denied;
        }

        $payload = json_decode($request->getContent(), true);
        $logs    = is_array($payload['logs'] ?? null) ? $payload['logs'] : [];

        $accepted = [];
        // Guards against a single batch carrying more than one alert-worthy entry (e.g. a device
        // catching up after an outage) — the DB cooldown check alone wouldn't see an entry from
        // earlier in the same, still-unflushed batch.
        $alertSentThisRequest = false;

        foreach ($logs as $log) {
            $logId = $log['log_id'] ?? null;

            if (!is_string($logId) || $logId === '') {
                continue;
            }

            if ($this->doorLogRepository->findOneByLogId($logId) === null) {
                $level    = is_string($log['level'] ?? null) ? $log['level'] : DoorLog::LEVEL_INFO;
                $category = is_string($log['category'] ?? null) ? $log['category'] : 'hardware';
                $reason   = is_string($log['reason'] ?? null) ? $log['reason'] : 'unknown';
                $message  = is_string($log['message'] ?? null) ? $log['message'] : '';
                $timestamp = $this->parseTimestamp($log['timestamp'] ?? null) ?? new \DateTimeImmutable();
                $context   = is_array($log['context'] ?? null) ? $log['context'] : null;

                $entry = new DoorLog($logId, $level, $category, $reason, $message, $timestamp, $doorId);
                $entry->setContext($context);
                $this->em->persist($entry);

                // Server-side alerting — see door-access-spec.md § Server-side alerting: an
                // explicit allowlist of reasons (not "every error"), deduped by never re-sending
                // for a log_id already stored, and rate-limited per door so a stuck sensor can't
                // flood the inbox.
                if (!$alertSentThisRequest && $entry->isAlertWorthy()
                    && !$this->doorLogRepository->hasRecentAlert($doorId, new \DateTimeImmutable('-' . self::ALERT_COOLDOWN_MINUTES . ' minutes'))
                ) {
                    $entry->markAlertSent();
                    $this->doorAlertMailer->sendAlert($entry);
                    $alertSentThisRequest = true;
                }
            }

            // Every recognised log_id is acknowledged regardless of whether it was new or already
            // stored — the device just needs this to stop retrying it.
            $accepted[] = $logId;
        }

        $this->em->flush();

        return new JsonResponse([
            'accepted'             => $accepted,
            'min_firmware_version' => $this->doorMinFirmwareVersion,
        ], 202);
    }

    /**
     * Device health check-in — logged for ops visibility, nothing persisted (no door/device table
     * yet, per the spec's single-door assumption). Was a bare 204 originally, but every response
     * needs to carry min_firmware_version (§ OTA), and a 204 can't have a body — so this is now a
     * 200 with a minimal JSON body instead.
     */
    #[Route('/heartbeat', name: 'app_api_door_heartbeat', requirements: ['doorId' => '\d+'], methods: ['POST'])]
    public function heartbeat(Request $request, int $doorId): Response
    {
        if ($denied = $this->checkDeviceAuth($request)) {
            return $denied;
        }

        $payload = json_decode($request->getContent(), true) ?: [];

        error_log(sprintf(
            'Door %d heartbeat: firmware=%s uptime=%ss relay=%s cached_credentials=%s',
            $doorId,
            $payload['firmware_version'] ?? 'unknown',
            $payload['uptime_s'] ?? '?',
            $payload['relay_state'] ?? 'unknown',
            $payload['cached_credential_count'] ?? '?',
        ));

        return new JsonResponse(['min_firmware_version' => $this->doorMinFirmwareVersion], 200);
    }

    private function applyAttendeeAccess(string $eventId, array $event): void
    {
        $stage        = $event['stage'] ?? null;
        $credentialId = (int) ($event['credential_id'] ?? 0);
        $attendee     = $credentialId ? $this->attendeeRepository->find($credentialId) : null;

        if (!is_string($stage) || !$attendee instanceof Attendee) {
            return;
        }

        $timestamp = $this->timestampForStage($event, $stage);
        $this->doorAccessService->applyAttendeeAccessEvent($eventId, $stage, $attendee, $timestamp);
    }

    private function applyKeyholderAccess(string $eventId, array $event): void
    {
        $stage  = $event['stage'] ?? null;
        $userId = (int) ($event['user_id'] ?? 0);
        $user   = $userId ? $this->userRepository->find($userId) : null;

        if (!is_string($stage) || !$user instanceof User) {
            return;
        }

        $timestamp = $this->timestampForStage($event, $stage);
        $this->doorAccessService->applyKeyholderAccessEvent($eventId, $stage, $user, $timestamp);
    }

    private function applyUnexpectedOpen(string $eventId, array $event): void
    {
        $stage = $event['stage'] ?? null;

        if (!is_string($stage)) {
            return;
        }

        $timestamp = $this->timestampForStage($event, $stage);
        $this->doorAccessService->applyUnexpectedOpenEvent($eventId, $stage, $timestamp);
    }

    private function applyAccessDenied(string $eventId, int $doorId, array $event): void
    {
        $credentialId = (int) ($event['credential_id'] ?? 0);
        $attendee     = $credentialId ? $this->attendeeRepository->find($credentialId) : null;
        $reason       = is_string($event['reason'] ?? null) ? $event['reason'] : null;
        $timestamp    = $this->parseTimestamp($event['timestamp'] ?? null) ?? new \DateTimeImmutable();

        $this->doorAccessService->applyAccessDenied($eventId, $attendee, $reason, $timestamp);

        error_log(sprintf('Door %d access denied: credential_id=%s reason=%s', $doorId, $credentialId ?: 'unknown', $reason ?? 'unknown'));
    }

    /** Picks whichever timestamp field matches {stage} out of the event payload, falling back to now. */
    private function timestampForStage(array $event, string $stage): \DateTimeImmutable
    {
        $field = match ($stage) {
            AccessEvent::STAGE_AUTHORIZED  => 'authorized_at',
            AccessEvent::STAGE_DOOR_OPEN   => 'door_open_at',
            AccessEvent::STAGE_DOOR_CLOSED => 'door_closed_at',
            default => null,
        };

        return ($field !== null ? $this->parseTimestamp($event[$field] ?? null) : null) ?? new \DateTimeImmutable();
    }

    private function parseTimestamp(?string $raw): ?\DateTimeImmutable
    {
        if (!$raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /** Bearer-token auth for the physical door device — returns a 401 JsonResponse to short-circuit with, or null if authorised. */
    private function checkDeviceAuth(Request $request): ?JsonResponse
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ') || !hash_equals($this->doorApiKey, substr($header, 7))) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return null;
    }
}
