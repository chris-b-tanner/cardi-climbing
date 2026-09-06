<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderRow;
use App\Entity\User;
use App\Service\Fulfilment\FulfilmentHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Owns a SalesOrder's path from draft to complete — payment collection, then dispatching each row to its product-type fulfilment handler. */
class SalesOrderService
{
    /** @param iterable<FulfilmentHandlerInterface> $fulfilmentHandlers */
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireIterator('app.fulfilment_handler')]
        private readonly iterable $fulfilmentHandlers,
    ) {}

    /** Completes an order with nothing to pay — no Payment is created. */
    public function completeFree(SalesOrder $order): void
    {
        $this->assertDraft($order);

        $this->markComplete($order);
        $this->em->flush();
    }

    /** Completes an order paid in cash — the cash has already changed hands, so the Payment is recorded as succeeded immediately. */
    public function completeWithCash(SalesOrder $order, User $takenBy): Payment
    {
        $this->assertDraft($order);

        $payment = new Payment();
        $payment->setUser($order->getUser());
        $payment->setOrder($order);
        $payment->setAmount($order->getTotal());
        $payment->setMethod(Payment::METHOD_CASH);
        $payment->setTakenBy($takenBy);
        $payment->setSucceededAt(new \DateTimeImmutable());

        $this->em->persist($payment);

        $this->markComplete($order);
        $this->em->flush();

        return $payment;
    }

    /**
     * Starts a card payment for an order — records a pending Payment against it and returns it so
     * the caller can send it to the card machine. The order stays draft until that's confirmed;
     * completeFromPayment() finishes the job once it is.
     */
    public function startCardPayment(SalesOrder $order, User $takenBy): Payment
    {
        $this->assertDraft($order);

        $payment = new Payment();
        $payment->setUser($order->getUser());
        $payment->setOrder($order);
        $payment->setAmount($order->getTotal());
        $payment->setMethod(Payment::METHOD_TERMINAL);
        $payment->setTakenBy($takenBy);

        $this->em->persist($payment);
        $this->em->flush();

        return $payment;
    }

    /** Completes the order a succeeded card/terminal Payment belongs to. Safe to call more than once (e.g. webhook retries) or with a payment that isn't order-linked (a no-op). */
    public function completeFromPayment(Payment $payment): void
    {
        $order = $payment->getOrder();
        if ($order === null || $order->getStatus() !== SalesOrder::STATUS_DRAFT) {
            return;
        }

        $this->markComplete($order);
        $this->em->flush();
    }

    /**
     * Whether adding {product} (at {occurrenceDate}, for {beneficiary}) alongside {existingLines}
     * would break the one-line-per-beneficiary rule for a beneficiary-relevant product (always false
     * for stock/service, which have no such rule). Shared by anything that builds up a set of lines
     * before they're real SalesOrderRows — the admin POS cart and the public self-serve cart both
     * call this with their own in-progress lines rather than duplicating the rule.
     *
     * @param iterable<array{0: Product, 1: ?\DateTimeImmutable, 2: User}> $existingLines each as [product, occurrenceDate, effectiveBeneficiary]
     */
    public function wouldConflictWithExisting(Product $product, ?\DateTimeImmutable $occurrenceDate, User $beneficiary, iterable $existingLines): bool
    {
        if (!$product->requiresBeneficiary()) {
            return false;
        }

        foreach ($existingLines as [$existingProduct, $existingOccurrenceDate, $existingBeneficiary]) {
            if ($existingProduct === $product && $existingOccurrenceDate == $occurrenceDate && $existingBeneficiary === $beneficiary) {
                return true;
            }
        }

        return false;
    }

    private function markComplete(SalesOrder $order): void
    {
        $this->assertValidRows($order);

        $order->setStatus(SalesOrder::STATUS_COMPLETE);

        foreach ($order->getRows() as $row) {
            $this->fulfil($row);
        }
    }

    /**
     * A beneficiary-relevant row (membership/credit/event ticket) is normally qty 1, and there's
     * never more than one such row per (product, beneficiary, occurrence) — the admin UI and cart
     * already enforce this when rows are added, but a completing order is checked again here since
     * fulfilment is where a violation would actually do damage (e.g. two Memberships from one line).
     *
     * The one exception is an event ticket for an unrestricted event: with no certification to
     * check per attendee, a single row can cover several anonymous seats bought by the same person
     * (e.g. 3 places on an open film night), so qty > 1 is allowed there.
     */
    private function assertValidRows(SalesOrder $order): void
    {
        $seen = [];
        foreach ($order->getRows() as $row) {
            $product = $row->getProduct();
            if (!$product->requiresBeneficiary()) {
                continue;
            }

            if ($row->getQty() !== 1 && !$this->allowsMultipleQty($product)) {
                throw new \LogicException(sprintf('Row #%d ("%s") requires a beneficiary and must have a quantity of 1, has %d.', $row->getId(), $product->getName(), $row->getQty()));
            }

            $key = $product->getId() . ':' . $row->getEffectiveBeneficiary()->getId() . ':' . ($row->getOccurrenceDate()?->format('Y-m-d') ?? '');
            if (isset($seen[$key])) {
                throw new \LogicException(sprintf('More than one row for the same beneficiary and product ("%s") in order #%d.', $product->getName(), $order->getId()));
            }
            $seen[$key] = true;
        }
    }

    private function allowsMultipleQty(Product $product): bool
    {
        if ($product->getProductType() !== Product::TYPE_EVENT_TICKET) {
            return false;
        }

        return $product->getEventTicketProduct()->getEvent()->getRestrictions()->isEmpty();
    }

    private function fulfil(SalesOrderRow $row): void
    {
        foreach ($this->fulfilmentHandlers as $handler) {
            if ($handler->supports($row->getProduct()->getProductType())) {
                $handler->fulfil($row);
                return;
            }
        }

        throw new \LogicException(sprintf('No fulfilment handler registered for product type "%s".', $row->getProduct()->getProductType()));
    }

    private function assertDraft(SalesOrder $order): void
    {
        if ($order->getStatus() !== SalesOrder::STATUS_DRAFT) {
            throw new \LogicException(sprintf('Order #%d is not a draft (status: %s).', $order->getId(), $order->getStatus()));
        }
    }
}
