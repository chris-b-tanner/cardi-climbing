<?php

namespace App\Repository;

use App\Entity\Note;
use App\Entity\PurchaseOrder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseOrder>
 */
class PurchaseOrderRepository extends ServiceEntityRepository
{
    /** The list page's tabs, each a set of statuses ('all' = no filter). */
    public const TABS = [
        'awaiting'  => [PurchaseOrder::STATUS_SUBMITTED],
        'drafts'    => [PurchaseOrder::STATUS_DRAFT],
        'open'      => [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PART_RECEIVED],
        'received'  => [PurchaseOrder::STATUS_RECEIVED],
        'cancelled' => [PurchaseOrder::STATUS_CANCELLED],
        'all'       => [],
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseOrder::class);
    }

    /**
     * The purchasing list. Searches the PO number, supplier reference and line descriptions; dates
     * filter on when the PO was raised.
     *
     * @return PurchaseOrder[]
     */
    public function search(string $tab = 'open', string $query = '', ?int $supplierId = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('po')
            ->join('po.supplier', 's')
            ->addSelect('s')
            ->orderBy('po.createdAt', 'DESC');

        $statuses = self::TABS[$tab] ?? self::TABS['open'];
        if ($statuses !== []) {
            $qb->andWhere('po.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        if ($query !== '') {
            $qb->leftJoin('po.lines', 'l')
                ->andWhere('po.number LIKE :q OR po.supplierReference LIKE :q OR l.description LIKE :q OR s.company LIKE :q')
                ->setParameter('q', '%' . $query . '%')
                ->distinct();
        }

        if ($supplierId !== null) {
            $qb->andWhere('po.supplier = :supplierId')
                ->setParameter('supplierId', $supplierId);
        }

        if ($from !== null) {
            $qb->andWhere('po.createdAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('po.createdAt < :to')
                ->setParameter('to', $to->modify('+1 day'));
        }

        return $qb->getQuery()->getResult();
    }

    public function countAwaitingApproval(): int
    {
        return (int) $this->createQueryBuilder('po')
            ->select('COUNT(po.id)')
            ->where('po.status = :status')
            ->setParameter('status', PurchaseOrder::STATUS_SUBMITTED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return PurchaseOrder[] Every PO from {supplier}, newest first — for the supplier's contact page. */
    public function findForSupplier(User $supplier): array
    {
        return $this->createQueryBuilder('po')
            ->where('po.supplier = :supplier')
            ->setParameter('supplier', $supplier)
            ->orderBy('po.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** The PO whose approval task {note} is, if any — such a note can only be cleared by approving or rejecting. */
    public function findByApprovalTask(Note $note): ?PurchaseOrder
    {
        return $this->findOneBy(['approvalTask' => $note]);
    }

    /**
     * Placed POs (anything that's been approved, excluding cancelled) raised between {from} and
     * {to} inclusive, for the spend report. Spend counts from approval: a draft or unapproved PO
     * hasn't committed any money.
     *
     * @return PurchaseOrder[]
     */
    public function findForSpend(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $supplierId = null): array
    {
        $qb = $this->createQueryBuilder('po')
            ->join('po.supplier', 's')
            ->addSelect('s')
            ->leftJoin('po.lines', 'l')
            ->addSelect('l')
            ->where('po.status IN (:statuses)')
            ->andWhere('po.approvedAt >= :from AND po.approvedAt < :to')
            ->setParameter('statuses', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PART_RECEIVED, PurchaseOrder::STATUS_RECEIVED])
            ->setParameter('from', $from)
            ->setParameter('to', $to->modify('+1 day'))
            ->orderBy('po.approvedAt', 'ASC');

        if ($supplierId !== null) {
            $qb->andWhere('po.supplier = :supplierId')
                ->setParameter('supplierId', $supplierId);
        }

        return $qb->getQuery()->getResult();
    }
}
