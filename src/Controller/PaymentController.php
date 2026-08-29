<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $stripeTerminalReaderId,
        private readonly string $stripePublishableKey,
    ) {}

    /** Standalone payment page — currently donations only, but its own page/routes so a future booking
     *  flow can link here with event/attendee context rather than being wedged into the account tabs. */
    #[Route('/donate', name: 'app_donate', methods: ['GET'])]
    public function donate(): Response
    {
        return $this->render('payment/donate.html.twig', [
            'stripePublishableKey' => $this->stripePublishableKey,
        ]);
    }

    /** Creates a Payment + Stripe PaymentIntent for an online card donation, returns the client secret for Stripe.js. */
    #[Route('/donate/intent', name: 'app_donate_intent', methods: ['POST'])]
    public function createIntent(Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('donate', $request->request->get('_csrf_token'))) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $amount = $this->parseAmount($request->request->get('amount', ''));
        if ($amount === null) {
            return new JsonResponse(['error' => 'Please enter a valid donation amount.'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();

        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setMethod(Payment::METHOD_ONLINE);

        $intent = $this->stripe->paymentIntents->create([
            'amount'               => $this->toMinorUnits($amount),
            'currency'             => $payment->getCurrency(),
            'payment_method_types' => ['card'],
            'description'          => 'Y Wal donation',
            'metadata'             => ['user_id' => (string) $user->getId()],
            'receipt_email'        => $user->getEmail(),
        ]);

        $payment->setStripePaymentIntentId($intent->id);

        $em->persist($payment);
        $em->flush();

        return new JsonResponse([
            'paymentId'    => $payment->getId(),
            'clientSecret' => $intent->client_secret,
        ]);
    }

    /** Creates a Payment + Stripe PaymentIntent and sends it to the club's S700 for the member to tap. */
    #[Route('/donate/terminal', name: 'app_donate_terminal', methods: ['POST'])]
    public function sendToTerminal(Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('donate', $request->request->get('_csrf_token'))) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        if (!$this->stripeTerminalReaderId) {
            return new JsonResponse(['error' => 'No card reader is configured.'], 500);
        }

        $amount = $this->parseAmount($request->request->get('amount', ''));
        if ($amount === null) {
            return new JsonResponse(['error' => 'Please enter a valid donation amount.'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();

        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setMethod(Payment::METHOD_TERMINAL);

        $intent = $this->stripe->paymentIntents->create([
            'amount'                => $this->toMinorUnits($amount),
            'currency'              => $payment->getCurrency(),
            'payment_method_types'  => ['card_present'],
            'capture_method'        => 'automatic',
            'description'           => 'Y Wal donation',
            'metadata'              => ['user_id' => (string) $user->getId()],
            'receipt_email'         => $user->getEmail(),
        ]);

        $payment->setStripePaymentIntentId($intent->id);

        $em->persist($payment);
        $em->flush();

        $this->stripe->terminal->readers->processPaymentIntent($this->stripeTerminalReaderId, [
            'payment_intent' => $intent->id,
        ]);

        return new JsonResponse(['paymentId' => $payment->getId()]);
    }

    /** Polled by the donate page while waiting for a terminal (or card) payment to settle. */
    #[Route('/donate/{id}/status', name: 'app_donate_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(Payment $payment, EntityManagerInterface $em): JsonResponse
    {
        if ($payment->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // The webhook is the normal way this gets set, but it can be delayed (or, locally, not
        // configured at all via `stripe listen`) — so fall back to asking Stripe directly.
        if ($payment->getSucceededAt() === null && $payment->getFailedAt() === null && $payment->getStripePaymentIntentId()) {
            $intent = $this->stripe->paymentIntents->retrieve($payment->getStripePaymentIntentId());

            if ($intent->status === 'succeeded') {
                $payment->setSucceededAt(new \DateTimeImmutable());
                $em->flush();
            } elseif ($intent->status === 'canceled' || $intent->last_payment_error) {
                $payment->setFailedAt(new \DateTimeImmutable());
                $payment->setFailureReason($intent->last_payment_error->message ?? null);
                $em->flush();
            }
        }

        return new JsonResponse(['status' => $payment->getStatus()]);
    }

    /** Validates and normalises a submitted amount into a decimal(8,2) string, or null if invalid. */
    private function parseAmount(string $raw): ?string
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;
        if ($value < 1 || $value > 5000) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    private function toMinorUnits(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
