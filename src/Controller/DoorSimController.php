<?php

namespace App\Controller;

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
 * A self-contained "virtual keypad" that mimics the physical door controller, for UAT with
 * non-staff volunteers — see door-access-spec.md. Gated by a shared secret in the URL (same
 * pattern as WebhookController) rather than a staff login, so it can be handed out as a link
 * without creating real accounts for external testers, and revoked by rotating one env var.
 *
 * Deliberately calls DoorAccessService directly rather than going over HTTP to the real
 * /v1/doors/... endpoints: that's the same production logic the physical door drives, without
 * ever putting the door's own DOOR_API_KEY in front of a tester's browser. A successful PIN entry
 * here is a real check-in — it consumes the attendee's actual booking PIN, same as a genuine tap.
 */
#[Route('/door-sim/{secret}')]
class DoorSimController extends AbstractController
{
    public function __construct(
        private readonly string $doorSimSecret,
        private readonly DoorAccessService $doorAccessService,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_door_sim', methods: ['GET'])]
    public function index(Request $request, string $secret): Response
    {
        $this->assertSecret($secret);

        return $this->render('door_sim/index.html.twig', [
            'secret' => $secret,
            'debug'  => $request->query->getBoolean('debug'),
        ]);
    }

    /**
     * Mimics the firmware's keypad-match step: PIN + now against this door's active credential
     * list, generic "denied" either way per the spec (no reason leaked to the UI) — unless the
     * caller opted into ?debug, in which case the response also carries everything needed to
     * see *why*: the server's own clock, the full active/near-future candidate pool, and (since
     * that pool is already filtered to pin_status=active) every booking that was ever issued this
     * exact PIN regardless of status, so a "but it's active in the database" report is easy to
     * check against what the server's clock actually thinks "now" and "valid_from/until" are.
     */
    #[Route('/attempt', name: 'app_door_sim_attempt', methods: ['POST'])]
    public function attempt(Request $request, string $secret): JsonResponse
    {
        $this->assertSecret($secret);

        $payload = json_decode($request->getContent(), true);
        $pin     = is_array($payload) ? trim((string) ($payload['pin'] ?? '')) : '';
        $debug   = is_array($payload) && ($payload['debug'] ?? false);

        $now = new \DateTimeImmutable();

        if (!preg_match('/^\d{6}$/', $pin)) {
            return new JsonResponse(array_merge(
                ['result' => 'denied'],
                $debug ? ['debug' => $this->buildDebugInfo($now, $pin)] : [],
            ));
        }

        $credentials = $this->doorAccessService->findCredentialsForDoor(DoorAccessService::SUPPORTED_DOOR_ID, $now);

        $match = null;
        foreach ($credentials as $credential) {
            if ($credential['pin'] === $pin && $now >= $credential['valid_from'] && $now <= $credential['valid_until']) {
                $match = $credential;
                break;
            }
        }

        if ($match === null) {
            error_log('Door sim: access denied for entered PIN (no matching active/in-window credential).');
            return new JsonResponse(array_merge(
                ['result' => 'denied'],
                $debug ? ['debug' => $this->buildDebugInfo($now, $pin)] : [],
            ));
        }

        $attendee = $this->attendeeRepository->find($match['credential_id']);
        if (!$attendee instanceof Attendee) {
            return new JsonResponse(array_merge(
                ['result' => 'denied'],
                $debug ? ['debug' => $this->buildDebugInfo($now, $pin)] : [],
            ));
        }

        $this->doorAccessService->markUsedViaDoor($attendee, $now);
        $this->em->flush();

        return new JsonResponse(array_merge(
            ['result' => 'granted', 'name' => $attendee->getUser()->getFirstName()],
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
