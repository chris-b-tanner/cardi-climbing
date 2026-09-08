<?php

namespace App\Controller\Api;

use App\Entity\Attendee;
use App\Repository\AttendeeRepository;
use App\Service\DoorAccessService;
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
        private readonly DoorAccessService $doorAccessService,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Active + near-future (next 2h) PIN credentials for this door — an authoritative full replace of the device's local cache on every poll. */
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
            static fn (array $c) => [
                'credential_id' => $c['credential_id'],
                'pin'           => $c['pin'],
                'valid_from'    => $c['valid_from']->format('Y-m-d\TH:i:s\Z'),
                'valid_until'   => $c['valid_until']->format('Y-m-d\TH:i:s\Z'),
                'status'        => $c['status'],
            ],
            $this->doorAccessService->findCredentialsForDoor($doorId, $now),
        );

        $etag = '"' . md5(json_encode($credentials)) . '"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response(null, 304, ['ETag' => $etag]);
        }

        return new JsonResponse([
            'server_time' => $now->format('Y-m-d\TH:i:s\Z'),
            'credentials' => $credentials,
        ], 200, ['ETag' => $etag]);
    }

    /** Batched, idempotent door events — credential_used marks the attendee checked in; access_denied is just acknowledged (nothing to persist for it in this slim schema). */
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

            if ($type === 'credential_used') {
                $this->applyCredentialUsed($event);
            } elseif ($type === 'access_denied') {
                error_log(sprintf(
                    'Door %d access denied: credential_id=%s reason=%s',
                    $doorId,
                    $event['credential_id'] ?? 'unknown',
                    $event['reason'] ?? 'unknown',
                ));
            }

            // Every recognised event_id is acknowledged regardless of whether the credential still
            // resolves to anything — the device just needs this to stop retrying it.
            $accepted[] = $eventId;
        }

        $this->em->flush();

        return new JsonResponse(['accepted' => $accepted], 202);
    }

    /** Device health check-in — logged for ops visibility, nothing persisted (no door/device table yet, per the spec's single-door assumption). */
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

        return new Response(null, 204);
    }

    private function applyCredentialUsed(array $event): void
    {
        $credentialId = (int) ($event['credential_id'] ?? 0);
        $attendee     = $credentialId ? $this->attendeeRepository->find($credentialId) : null;

        if (!$attendee instanceof Attendee) {
            return;
        }

        $timestamp = $this->parseTimestamp($event['timestamp'] ?? null) ?? new \DateTimeImmutable();

        $this->doorAccessService->markUsedViaDoor($attendee, $timestamp);
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
