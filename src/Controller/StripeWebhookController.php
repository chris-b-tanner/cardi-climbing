<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\Refund;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Event;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Authoritative source of truth for payment/refund outcomes — Stripe signs every event, so unlike
 * WebhookController's URL-secret scheme this verifies via the Stripe-Signature header instead.
 */
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $stripeWebhookSecret,
    ) {}

    #[Route('/webhook/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
    public function handle(
        Request $request,
        PaymentRepository $paymentRepository,
        RefundRepository $refundRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get('Stripe-Signature', ''),
                $this->stripeWebhookSecret,
            );
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid signature'], 400);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->onPaymentSucceeded($event, $paymentRepository, $em),
            'payment_intent.payment_failed' => $this->onPaymentFailed($event, $paymentRepository, $em),
            'charge.refunded' => $this->onChargeRefunded($event, $paymentRepository, $refundRepository, $em),
            default => null,
        };

        return new JsonResponse(['status' => 'ok']);
    }

    private function onPaymentSucceeded(Event $event, PaymentRepository $paymentRepository, EntityManagerInterface $em): void
    {
        $intent  = $event->data->object;
        $payment = $paymentRepository->findOneBy(['stripePaymentIntentId' => $intent->id]);

        if (!$payment || $payment->getSucceededAt() !== null) {
            return;
        }

        $payment->setSucceededAt(new \DateTimeImmutable());

        $attendee = $payment->getAttendee();
        if ($attendee !== null) {
            $attendee->setPaidAmount(number_format((float) $attendee->getPaidAmount() + (float) $payment->getAmount(), 2, '.', ''));
        }

        $em->flush();
    }

    private function onPaymentFailed(Event $event, PaymentRepository $paymentRepository, EntityManagerInterface $em): void
    {
        $intent  = $event->data->object;
        $payment = $paymentRepository->findOneBy(['stripePaymentIntentId' => $intent->id]);

        if (!$payment || $payment->getFailedAt() !== null) {
            return;
        }

        $payment->setFailedAt(new \DateTimeImmutable());
        $payment->setFailureReason($intent->last_payment_error->message ?? null);

        $em->flush();
    }

    /** Reconciles refunds issued directly in the Stripe Dashboard (ones issued via our admin action are already recorded). */
    private function onChargeRefunded(
        Event $event,
        PaymentRepository $paymentRepository,
        RefundRepository $refundRepository,
        EntityManagerInterface $em,
    ): void {
        $charge  = $event->data->object;
        $payment = $paymentRepository->findOneBy(['stripePaymentIntentId' => $charge->payment_intent]);

        if (!$payment) {
            return;
        }

        foreach ($charge->refunds->data as $stripeRefund) {
            if ($refundRepository->findOneBy(['stripeRefundId' => $stripeRefund->id])) {
                continue;
            }

            $refund = new Refund();
            $refund->setPayment($payment);
            $refund->setAmount(number_format($stripeRefund->amount / 100, 2, '.', ''));
            $refund->setStripeRefundId($stripeRefund->id);
            if ($stripeRefund->status === 'succeeded') {
                $refund->setSucceededAt(new \DateTimeImmutable());
            } elseif ($stripeRefund->status === 'failed') {
                $refund->setFailedAt(new \DateTimeImmutable());
            }

            $em->persist($refund);
        }

        $em->flush();
    }
}
