<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Service\Mailer\PaymentMailer;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;

/**
 * The Stripe side of a self-serve payment (a donation, or paying for the public cart) — starting a
 * PaymentIntent against a new Payment, and recording how it settled. markSucceeded()/markFailed()
 * are the one place a Payment's outcome gets applied, whether it arrives via the Stripe webhook or
 * a status-poll fallback asking Stripe directly, so both routes always have the same side effects.
 *
 * The admin point-of-sale's own card-reader flow lives in SalesOrderService::startCardPayment().
 */
class StripePaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeClient $stripe,
        private readonly PaymentMailer $paymentMailer,
        private readonly SalesOrderService $salesOrderService,
        private readonly string $stripeTerminalReaderId,
    ) {}

    /**
     * Records a pending online card Payment and creates its PaymentIntent, for Stripe.js to confirm in the browser.
     *
     * @return array{0: Payment, 1: string} the Payment, and the PaymentIntent's client secret
     */
    public function startOnlinePayment(User $user, string $amount, string $description, ?SalesOrder $order = null): array
    {
        $payment = $this->newPayment($user, $amount, Payment::METHOD_ONLINE, $order);

        $intent = $this->stripe->paymentIntents->create([
            'amount'               => self::toMinorUnits($amount),
            'currency'             => $payment->getCurrency(),
            'payment_method_types' => ['card'],
            'description'          => $description,
            'metadata'             => $this->metadata($user, $order),
            'receipt_email'        => $user->getEmail(),
        ]);

        $this->save($payment, $intent->id);

        return [$payment, $intent->client_secret];
    }

    /**
     * Records a pending terminal Payment and sends its PaymentIntent straight to the club's S700
     * for the payer to tap/insert.
     *
     * @throws \LogicException if no card reader is configured (STRIPE_TERMINAL_READER_ID unset)
     */
    public function startTerminalPayment(User $user, string $amount, string $description): Payment
    {
        if (!$this->stripeTerminalReaderId) {
            throw new \LogicException('No card reader is configured.');
        }

        $payment = $this->newPayment($user, $amount, Payment::METHOD_TERMINAL);

        $intent = $this->stripe->paymentIntents->create([
            'amount'               => self::toMinorUnits($amount),
            'currency'             => $payment->getCurrency(),
            'payment_method_types' => ['card_present'],
            'capture_method'       => 'automatic',
            'description'          => $description,
            'metadata'             => $this->metadata($user),
            'receipt_email'        => $user->getEmail(),
        ]);

        $this->save($payment, $intent->id);

        try {
            $this->stripe->terminal->readers->processPaymentIntent($this->stripeTerminalReaderId, [
                'payment_intent' => $intent->id,
            ]);
        } catch (\Throwable $e) {
            // Never reached the reader — fail it now rather than leave a "pending" payment behind
            // that will never settle (same as SalesOrderService::startCardPayment()).
            $this->markFailed($payment, 'Could not reach the card reader: ' . $e->getMessage());

            throw $e;
        }

        return $payment;
    }

    /**
     * Status-poll fallback: the webhook is the normal way a Payment settles, but it can be delayed
     * (or, locally, not configured at all via `stripe listen`) — so ask Stripe directly while the
     * Payment is still pending.
     */
    public function refreshFromStripe(Payment $payment): void
    {
        if ($payment->getSucceededAt() !== null || $payment->getFailedAt() !== null || !$payment->getStripePaymentIntentId()) {
            return;
        }

        $intent = $this->stripe->paymentIntents->retrieve($payment->getStripePaymentIntentId());

        if ($intent->status === 'succeeded') {
            $this->markSucceeded($payment);
        } elseif ($intent->status === 'canceled' || $intent->last_payment_error) {
            $this->markFailed($payment, $intent->last_payment_error->message ?? null);
        }
    }

    /** Records {payment} as succeeded and applies everything that follows from that. Safe to call more than once (webhook retries, a poll racing the webhook) — only the first call does anything. */
    public function markSucceeded(Payment $payment): void
    {
        if ($payment->getSucceededAt() !== null) {
            return;
        }

        $payment->setSucceededAt(new \DateTimeImmutable());

        $attendee = $payment->getAttendee();
        if ($attendee !== null) {
            $attendee->setPaidAmount(number_format((float) $attendee->getPaidAmount() + (float) $payment->getAmount(), 2, '.', ''));
        }

        $this->em->flush();

        // A no-op for a payment with no order (e.g. a donation).
        $this->salesOrderService->completeFromPayment($payment);

        try {
            $this->paymentMailer->sendReceipt($payment);
        } catch (\Throwable $e) {
            // The payment is already saved as succeeded at this point — an email failure here
            // shouldn't turn into a 500 (or a webhook error Stripe would endlessly retry).
            error_log('Payment receipt email failed for payment ' . $payment->getId() . ': ' . $e->getMessage());
        }
    }

    /** Records {payment} as failed. Safe to call more than once — only the first call does anything. */
    public function markFailed(Payment $payment, ?string $reason): void
    {
        if ($payment->getFailedAt() !== null) {
            return;
        }

        $payment->setFailedAt(new \DateTimeImmutable());
        $payment->setFailureReason($reason);
        $this->em->flush();
    }

    /** A decimal amount string (e.g. "12.50") in Stripe's minor units (pence). */
    public static function toMinorUnits(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function newPayment(User $user, string $amount, string $method, ?SalesOrder $order = null): Payment
    {
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setMethod($method);
        if ($order !== null) {
            $payment->setOrder($order);
        }

        return $payment;
    }

    private function save(Payment $payment, string $stripePaymentIntentId): void
    {
        $payment->setStripePaymentIntentId($stripePaymentIntentId);

        $this->em->persist($payment);
        $this->em->flush();
    }

    /** @return array<string, string> */
    private function metadata(User $user, ?SalesOrder $order = null): array
    {
        $metadata = ['user_id' => (string) $user->getId()];
        if ($order !== null) {
            $metadata['order_id'] = (string) $order->getId();
        }

        return $metadata;
    }
}
