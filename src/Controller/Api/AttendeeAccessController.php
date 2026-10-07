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
 * Staff-facing counterpart to the door device's own API (see door-access-spec.md) — manual
 * reception check-in. Authenticated as a logged-in staff member, not the device bearer token.
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
