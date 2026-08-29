<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/donate')]
#[IsGranted('ROLE_USER')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $stripeTerminalReaderId,
    ) {}

    /** Creates a Payment + Stripe PaymentIntent for an online card donation, returns the client secret for Stripe.js. */
    #[Route('/intent', name: 'app_donate_intent', methods: ['POST'])]
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
            'amount'   => $this->toMinorUnits($amount),
            'currency' => $payment->getCurrency(),
            'metadata' => ['user_id' => (string) $user->getId()],
            'receipt_email' => $user->getEmail(),
            'automatic_payment_methods' => ['enabled' => true],
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
    #[Route('/terminal', name: 'app_donate_terminal', methods: ['POST'])]
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

    /** Polled by the account page while waiting for a terminal (or redirect-based online) payment to settle. */
    #[Route('/{id}/status', name: 'app_donate_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(Payment $payment): JsonResponse
    {
        if ($payment->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Not found.'], 404);
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
