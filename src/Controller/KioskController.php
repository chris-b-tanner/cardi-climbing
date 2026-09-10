<?php

namespace App\Controller;

use App\Entity\UserCertification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Walk-in kiosk entry — for a reception tablet, or a wall-mounted one in kiosk mode. Lets someone
 * with no login of their own (added at reception with just a name, no email) get straight into
 * the certification wizard, authenticating with the record's own ID plus their surname instead of
 * a password or emailed link. See AdminController::confirmCertification() for where the ID is
 * generated and handed over, and AccountController::completeCertification() for the auto-logout
 * once they're done (this is a shared device — it must not stay signed in as them afterwards).
 *
 * Anti-brute-force: only a record that's still "in progress" (not yet completed/approved/
 * cancelled) and started within the last 30 minutes can be claimed this way — an ID guessed
 * outside that narrow window, or one that's already been used, never matches anything.
 */
class KioskController extends AbstractController
{
    private const CLAIM_WINDOW = 'PT30M';

    #[Route('/kiosk/certification', name: 'app_kiosk_certification', methods: ['GET', 'POST'])]
    public function certification(Request $request, EntityManagerInterface $em, Security $security): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('kiosk_certification', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $recordId = (int) trim($request->request->get('recordId', ''));
            $surname  = trim($request->request->get('surname', ''));
            $record   = $recordId > 0 ? $em->getRepository(UserCertification::class)->find($recordId) : null;

            if ($this->isClaimable($record, $surname)) {
                $security->login($record->getUser());
                $request->getSession()->set('kiosk_mode', true);

                return $this->redirectToRoute('app_account_certification_complete', ['recordId' => $record->getId()]);
            }

            // Deliberately generic — never reveal which part (ID vs surname vs expiry vs status)
            // was wrong, so a brute-force attempt learns nothing from the response.
            $error = "That ID and surname don't match an induction awaiting completion. Check with reception.";
        }

        return $this->render('kiosk/certification.html.twig', ['error' => $error]);
    }

    private function isClaimable(?UserCertification $record, string $surname): bool
    {
        if (!$record || $surname === '') {
            return false;
        }

        if ($record->getStatus() !== UserCertification::STATUS_IN_PROGRESS) {
            return false;
        }

        if ($record->getStartedAt() < (new \DateTimeImmutable())->sub(new \DateInterval(self::CLAIM_WINDOW))) {
            return false;
        }

        $holderSurname = trim($record->getUser()->getLastName() ?? '');

        return $holderSurname !== '' && mb_strtolower($holderSurname) === mb_strtolower($surname);
    }
}
