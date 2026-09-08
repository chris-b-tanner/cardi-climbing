<?php

namespace App\Controller\Api;

use App\Entity\Attendee;
use App\Entity\User;
use App\Service\DoorAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff-facing counterparts to the door device's own API (see door-access-spec.md) — manual
 * reception check-in, and issuing a fresh PIN when a door opened but the member didn't get
 * through in time. Authenticated as a logged-in staff member, not the device bearer token.
 */
#[Route('/v1/attendees/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_TEAM')]
class AttendeeAccessController extends AbstractController
{
    public function __construct(
        private readonly DoorAccessService $doorAccessService,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Same underlying mutation as the door's own credential_used event, from the reception desk instead. */
    #[Route('/check-in', name: 'app_api_attendee_check_in', methods: ['POST'])]
    public function checkIn(Request $request, Attendee $attendee): JsonResponse
    {
        if (!$this->isCsrfTokenValid('attendee_check_in_' . $attendee->getId(), $this->csrfToken($request))) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        /** @var User $staff */
        $staff = $this->getUser();

        try {
            $this->doorAccessService->checkInManually($attendee, $staff);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        $this->em->flush();

        return new JsonResponse([
            'checked_in_at'     => $attendee->getCheckedInAt()->format('Y-m-d\TH:i:s\Z'),
            'checked_in_method' => $attendee->getCheckedInMethod(),
        ]);
    }

    /** Issues a fresh PIN for the same booking/session window — e.g. the door opened but the member didn't get through in time. */
    #[Route('/regenerate-pin', name: 'app_api_attendee_regenerate_pin', methods: ['POST'])]
    public function regeneratePin(Request $request, Attendee $attendee): JsonResponse
    {
        if (!$this->isCsrfTokenValid('attendee_regenerate_pin_' . $attendee->getId(), $this->csrfToken($request))) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        if ($attendee->getPin() === null) {
            return new JsonResponse(['error' => 'This booking has no door PIN to regenerate.'], 422);
        }

        try {
            $pin = $this->doorAccessService->regeneratePin($attendee);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        $this->em->flush();

        return new JsonResponse([
            'pin'         => $pin,
            'valid_from'  => $this->doorAccessService->computeValidFrom($attendee)->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => $this->doorAccessService->computeValidUntil($attendee)->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** Reads the CSRF token from either a JSON body or a form-encoded one, so this can be called by a plain fetch() as well as a form submit. */
    private function csrfToken(Request $request): string
    {
        if ($request->request->has('_csrf_token')) {
            return $request->request->get('_csrf_token', '');
        }

        $payload = json_decode($request->getContent(), true);
        return is_array($payload) ? (string) ($payload['_csrf_token'] ?? '') : '';
    }
}
