<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\InventoryMovement;
use App\Entity\Note;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\PurchaseOrderReceipt;
use App\Entity\User;
use App\Service\Mailer\NoteAssignmentMailer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every status change on a purchase order, plus receiving deliveries — see purchase-orders-spec.md.
 * Each step writes a system note on the PO (addedBy = null, so only an owner can delete it) naming
 * who did it, so the notes panel doubles as the order's history. Methods flush.
 *
 * Callers check permissions (approve/reject/order/cancel/line cost are ROLE_ADMIN); these methods
 * check the order is in the right state, and throw \LogicException with a message to show if not.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteableResolver $noteableResolver,
        private readonly NoteAssignmentMailer $noteAssignmentMailer,
        private readonly AssetHistory $assetHistory,
    ) {}

    /** Saves a new draft and gives it its PO number. */
    public function create(PurchaseOrder $po, User $by): void
    {
        $po->setCreatedBy($by);
        $this->em->persist($po);
        $this->em->flush();

        $po->assignNumber();
        $this->record($po, 'Raised as a draft', $by);
        $this->em->flush();
    }

    /** Sends a draft for approval, with a pinned "Approval needed" task for the admins. */
    public function submit(PurchaseOrder $po, User $by): void
    {
        $this->assertSubmittable($po);

        $po->markSubmitted($by);

        $task = new Note();
        $task->setNoteable($po);
        $task->setContent(sprintf('Approval needed: %s, %s, £%s', $po->getNumber(), $po->getSupplier()->getSupplierName(), $po->getGrossTotal()));
        $task->setAddedBy(null);
        $task->pin($by);
        $this->em->persist($task);
        $po->setApprovalTask($task);

        $this->record($po, 'Submitted for approval (£' . $po->getGrossTotal() . ' inc VAT)', $by);
        $this->em->flush();
    }

    /** An admin's own draft: submit and approve in one step, with no approval task since there's nobody to ask. */
    public function submitAndApprove(PurchaseOrder $po, User $admin): void
    {
        $this->assertSubmittable($po);

        $po->markSubmitted($admin);
        $po->markApproved($admin);

        $this->record($po, 'Submitted and approved (£' . $po->getGrossTotal() . ' inc VAT)', $admin);
        $this->em->flush();
    }

    /** The person who raised it pulls a submitted PO back to draft to change it. */
    public function pullBack(PurchaseOrder $po, User $by): void
    {
        if ($po->getStatus() !== PurchaseOrder::STATUS_SUBMITTED) {
            throw new \LogicException('Only an order awaiting approval can be pulled back.');
        }

        $po->markDraft();
        $this->closeApprovalTask($po, $by);

        $this->record($po, 'Pulled back to draft', $by);
        $this->em->flush();
    }

    public function approve(PurchaseOrder $po, User $admin): void
    {
        if ($po->getStatus() !== PurchaseOrder::STATUS_SUBMITTED) {
            throw new \LogicException('Only an order awaiting approval can be approved.');
        }

        $po->markApproved($admin);
        $this->closeApprovalTask($po, $admin);

        $this->record($po, 'Approved', $admin);
        $this->em->flush();
    }

    /** Back to draft, with the reason as a pinned note assigned to whoever raised it — which emails them. */
    public function reject(PurchaseOrder $po, User $admin, string $reason): void
    {
        if ($po->getStatus() !== PurchaseOrder::STATUS_SUBMITTED) {
            throw new \LogicException('Only an order awaiting approval can be rejected.');
        }

        $po->markDraft();
        $this->closeApprovalTask($po, $admin);

        $note = new Note();
        $note->setNoteable($po);
        $note->setContent('Rejected: ' . $reason);
        $note->setAddedBy($admin);
        $note->pin($admin);
        $raisedBy = $po->getCreatedBy();
        if ($raisedBy !== null && $raisedBy->isStaff() && $raisedBy !== $admin) {
            $note->setAssignedTo($raisedBy);
        }
        $this->em->persist($note);
        $this->em->flush();

        if ($note->getAssignedTo() !== null) {
            $this->noteAssignmentMailer->sendAssigned($note, $this->noteableResolver->resolve($note, absolute: true), $admin);
        }
    }

    /** Placed with the supplier — by phone, online, or after emailing the PDF (see the controller). */
    public function markOrdered(PurchaseOrder $po, User $admin, ?string $how = null): void
    {
        if ($po->getStatus() !== PurchaseOrder::STATUS_APPROVED) {
            throw new \LogicException('Only an approved order can be marked as ordered.');
        }

        $po->markOrdered($admin);
        $this->record($po, 'Ordered' . ($how ? ' ' . $how : ''), $admin);
        $this->em->flush();
    }

    public function cancel(PurchaseOrder $po, User $admin, string $reason): void
    {
        if (!$po->isCancellable()) {
            throw new \LogicException('This order can\'t be cancelled once anything on it has been received.');
        }

        $po->markCancelled($admin, $reason);
        $this->closeApprovalTask($po, $admin);

        $this->record($po, 'Cancelled: ' . $reason, $admin);
        $this->em->flush();
    }

    /** An admin corrects an approved line's unit cost (price rise, substitution) — only until that line has had a delivery. */
    public function changeLineCost(PurchaseOrderLine $line, string $unitCost, string $reason, User $admin): void
    {
        $po = $line->getPurchaseOrder();
        if (!in_array($po->getStatus(), [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PART_RECEIVED], true)) {
            throw new \LogicException('Line costs can only be changed this way on an approved order.');
        }
        if ($line->getReceipts()->count() > 0) {
            throw new \LogicException('This line has already been received, so its stock went in at the old cost.');
        }

        $old = $line->getUnitCost();
        $line->setUnitCost($unitCost);

        $this->record($po, sprintf('Unit cost of "%s" changed from £%s to £%s: %s', $line->getDescription(), $old, $unitCost, $reason), $admin);
        $this->em->flush();
    }

    /**
     * Records one delivery. {quantities} maps line id → qty received now (lines missing or at 0 are
     * skipped); {assetLines} are the non-stock line ids to add to the asset register, and
     * {individualLines} those to add as one asset per unit. Stock lines put stock in at the price
     * paid and re-average the product's cost; see purchase-orders-spec.md § Average costing.
     *
     * @param array<int, int> $quantities
     * @param int[] $assetLines
     * @param int[] $individualLines
     * @return int how many lines were received
     */
    public function receive(PurchaseOrder $po, array $quantities, array $assetLines, array $individualLines, \DateTimeImmutable $receivedAt, ?string $note, User $by): int
    {
        if (!$po->isAwaitingDelivery()) {
            throw new \LogicException('Deliveries can only be recorded once the order has been placed.');
        }
        if ($receivedAt > new \DateTimeImmutable()) {
            throw new \LogicException('The delivery date can\'t be in the future.');
        }

        $toReceive = [];
        foreach ($po->getLines() as $line) {
            $qty = $quantities[$line->getId()] ?? 0;
            if ($qty <= 0) {
                continue;
            }
            if ($qty > $line->getQtyOutstanding()) {
                throw new \LogicException(sprintf('Only %d of "%s" are still to come — receive the rest as a stock adjustment if extra arrived.', $line->getQtyOutstanding(), $line->getDescription()));
            }
            $toReceive[] = [$line, $qty];
        }

        if ($toReceive === []) {
            throw new \LogicException('Enter how many of at least one line arrived.');
        }

        $summary      = [];
        $newAssets    = [];
        // Stock received earlier in this same delivery, per product, so two lines for one product average correctly.
        $receivedNow  = [];

        foreach ($toReceive as [$line, $qty]) {
            $receipt = new PurchaseOrderReceipt();
            $receipt->setQty($qty);
            $receipt->setReceivedAt($receivedAt);
            $receipt->setReceivedBy($by);
            $receipt->setNote($note);
            $line->addReceipt($receipt);
            $this->em->persist($receipt);

            $stockProduct = $line->getStockProduct();
            if ($stockProduct !== null) {
                $key    = $stockProduct->getProduct()->getId();
                $onHand = $stockProduct->getCurrentStock() + ($receivedNow[$key] ?? 0);

                $newCost = $onHand > 0
                    ? ($onHand * (float) $stockProduct->getCostPrice() + $qty * (float) $line->getUnitCost()) / ($onHand + $qty)
                    : (float) $line->getUnitCost();
                $stockProduct->setCostPrice(number_format($newCost, 2, '.', ''));
                $receivedNow[$key] = ($receivedNow[$key] ?? 0) + $qty;

                $movement = new InventoryMovement();
                $movement->setStockProduct($stockProduct);
                $movement->setQuantityChange($qty);
                $movement->setNetPrice($line->getUnitCost());
                $movement->setReason(InventoryMovement::REASON_PURCHASE);
                $movement->setNote($po->getNumber());
                $movement->setCreatedBy($by);
                $this->em->persist($movement);

                $receipt->setInventoryMovement($movement);
                $receipt->setAverageCostAfter($stockProduct->getCostPrice());
            } elseif (in_array($line->getId(), $assetLines, true)) {
                $individual = in_array($line->getId(), $individualLines, true) && $qty > 1;
                foreach ($individual ? array_fill(0, $qty, 1) : [$qty] as $assetQty) {
                    $newAssets[] = $this->newAssetFromLine($line, $assetQty, $receivedAt, $by);
                }
            }

            $summary[] = $qty . ' × ' . $line->getDescription();
        }

        $po->refreshReceivedStatus();

        $this->em->flush();

        foreach ($newAssets as $asset) {
            $this->assetHistory->record($asset, 'Added from ' . $po->getNumber() . ' delivery', $by);
        }

        $this->record(
            $po,
            'Delivery received ' . $receivedAt->format('d M Y') . ': ' . implode(', ', $summary)
                . ($newAssets ? ' (' . count($newAssets) . ' added to the asset register)' : '')
                . ($note ? '. ' . $note : ''),
            $by,
        );
        $this->em->flush();

        return count($toReceive);
    }

    /** Writes a system history note on {po}. Callers flush. */
    public function record(PurchaseOrder $po, string $text, ?User $by): void
    {
        $note = new Note();
        $note->setNoteable($po);
        $note->setContent($by ? $text . ' — ' . $by->getDisplayName() : $text);
        $note->setAddedBy(null);

        $this->em->persist($note);
    }

    private function newAssetFromLine(PurchaseOrderLine $line, int $qty, \DateTimeImmutable $receivedAt, User $by): Asset
    {
        $cost = number_format($qty * (float) $line->getUnitCost(), 2, '.', '');

        $asset = new Asset();
        $asset->setName(mb_substr($line->getDescription(), 0, 150));
        $asset->setCategory($line->getAssetCategory());
        $asset->setQuantity($qty);
        $asset->setStatus(Asset::STATUS_IN_USE);
        $asset->setAcquisitionType(Asset::ACQUISITION_PURCHASED);
        $asset->setAcquiredAt(\DateTimeImmutable::createFromFormat('!Y-m-d', $receivedAt->format('Y-m-d')));
        $asset->setSupplier($line->getPurchaseOrder()->getSupplier());
        $asset->setPurchaseOrderLine($line);
        $asset->setPurchaseCost($cost);
        $asset->setReplacementValue($cost);
        $asset->setCreatedBy($by);

        $this->em->persist($asset);

        return $asset;
    }

    private function assertSubmittable(PurchaseOrder $po): void
    {
        if (!$po->isDraft()) {
            throw new \LogicException('Only a draft can be submitted.');
        }
        if ($po->getLines()->isEmpty()) {
            throw new \LogicException('Add at least one line before submitting.');
        }
    }

    private function closeApprovalTask(PurchaseOrder $po, User $by): void
    {
        $task = $po->getApprovalTask();
        if ($task !== null) {
            $task->complete($by);
            $po->setApprovalTask(null);
        }
    }
}
