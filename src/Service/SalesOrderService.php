<?php

namespace App\Service;

use App\Entity\Payment;
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

    private function markComplete(SalesOrder $order): void
    {
        $order->setStatus(SalesOrder::STATUS_COMPLETE);

        foreach ($order->getRows() as $row) {
            $this->fulfil($row);
        }
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
