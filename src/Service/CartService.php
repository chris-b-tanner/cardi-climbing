<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderRow;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A member's self-serve shopping cart — held in session, not persisted as a SalesOrder until
 * checkout(), so browsing/adding never leaves a draft order behind; only actually paying does.
 * Every product sold here requires a beneficiary (Product::requiresBeneficiary()) and the only
 * people it can be bought for are the member themself or their own dependents — so a line is
 * always exactly one of a product for one person, never a quantity.
 */
class CartService
{
    private const SESSION_KEY = 'cart';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProductRepository $productRepository,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly SalesOrderService $salesOrderService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * The cart's lines, resolved to real entities. A stale entry — its product gone inactive, or
     * its beneficiary no longer this member or their dependent — is silently dropped rather than
     * shown broken, and the session is updated to match.
     *
     * @return array<string, array{product: Product, beneficiary: User, occurrenceDate: ?\DateTimeImmutable, qty: int, lineTotal: string}>
     */
    public function getLines(User $user): array
    {
        $raw      = $this->readRaw();
        $eligible = $this->eligibleBeneficiaries($user);

        $lines = [];
        foreach ($raw as $key => $entry) {
            $product     = $this->productRepository->find($entry['productId']);
            $beneficiary = $eligible[$entry['beneficiaryId']] ?? null;

            if (!$product instanceof Product || !$product->isActive() || $beneficiary === null) {
                continue;
            }

            $qty = max(1, (int) ($entry['qty'] ?? 1));

            $lines[$key] = [
                'product'        => $product,
                'beneficiary'    => $beneficiary,
                'occurrenceDate' => $this->parseOccurrenceDate($entry['occurrenceDate']),
                'qty'            => $qty,
                'lineTotal'      => number_format((float) $product->getPrice() * $qty, 2, '.', ''),
            ];
        }

        if (count($lines) !== count($raw)) {
            $this->writeRaw(array_intersect_key($raw, $lines));
        }

        return $lines;
    }

    /** @return string decimal(8,2) */
    public function getTotal(User $user): string
    {
        $total = 0.0;
        foreach ($this->getLines($user) as $line) {
            $total += (float) $line['lineTotal'];
        }
        return number_format($total, 2, '.', '');
    }

    /** @throws \InvalidArgumentException if the product, beneficiary, occurrence date, or qty isn't valid, or this exact line is already in the cart */
    public function addLine(User $user, int $productId, ?int $beneficiaryId, ?string $occurrenceDateRaw, int $qty = 1): void
    {
        $product = $this->productRepository->find($productId);
        if (!$product instanceof Product || !$product->isActive() || !$product->requiresBeneficiary()) {
            throw new \InvalidArgumentException('Choose a valid product.');
        }

        $eligible    = $this->eligibleBeneficiaries($user);
        $beneficiary = $eligible[$beneficiaryId ?? $user->getId()] ?? null;
        if ($beneficiary === null) {
            throw new \InvalidArgumentException('Choose yourself or one of your dependents.');
        }

        $occurrenceDate     = null;
        $eventTicketProduct = $product->getEventTicketProduct();
        if ($eventTicketProduct !== null) {
            $event = $eventTicketProduct->getEvent();

            // Checked against the beneficiary, not necessarily the logged-in member — a ticket can
            // be bought for a dependent, whose certification status may differ from their own.
            if (!$event->allowsUser($beneficiary)) {
                throw new \InvalidArgumentException('The selected person doesn\'t hold the certification required for this event.');
            }

            $ticketMembershipType = $eventTicketProduct->getMembershipType();
            if ($ticketMembershipType !== null && !$beneficiary->hasActiveMembershipType($ticketMembershipType)) {
                throw new \InvalidArgumentException('That ticket price is only available to ' . $ticketMembershipType->getName() . ' members.');
            }

            if ($event->isRecurring()) {
                $occurrenceDate = $this->parseOccurrenceDate($occurrenceDateRaw);

                if ($occurrenceDate === null || !$event->isValidForDate($occurrenceDate)) {
                    throw new \InvalidArgumentException('Choose a valid date for this event.');
                }
            }
        }

        $this->assertQtyAllowed($product, $qty, $occurrenceDate);

        $existingLines = [];
        foreach ($this->getLines($user) as $line) {
            $existingLines[] = [$line['product'], $line['occurrenceDate'], $line['beneficiary']];
        }

        if ($this->salesOrderService->wouldConflictWithExisting($product, $occurrenceDate, $beneficiary, $existingLines)) {
            throw new \InvalidArgumentException('That\'s already in your cart for this person.');
        }

        $raw = $this->readRaw();
        $raw[$this->lineKey($product->getId(), $beneficiary->getId(), $occurrenceDate)] = [
            'productId'      => $product->getId(),
            'beneficiaryId'  => $beneficiary->getId(),
            'occurrenceDate' => $occurrenceDate?->format('Y-m-d'),
            'qty'            => $qty,
        ];
        $this->writeRaw($raw);
    }

    public function removeLine(string $key): void
    {
        $raw = $this->readRaw();
        unset($raw[$key]);
        $this->writeRaw($raw);
    }

    /** @throws \InvalidArgumentException if the line isn't in the cart, its product has gone inactive, or the new qty isn't allowed */
    public function updateQty(User $user, string $key, int $qty): void
    {
        $raw = $this->readRaw();
        if (!isset($raw[$key])) {
            throw new \InvalidArgumentException('That item is no longer in your cart.');
        }

        $product = $this->productRepository->find($raw[$key]['productId']);
        if (!$product instanceof Product || !$product->isActive()) {
            throw new \InvalidArgumentException('That item is no longer available.');
        }

        $this->assertQtyAllowed($product, $qty, $this->parseOccurrenceDate($raw[$key]['occurrenceDate']));

        $raw[$key]['qty'] = $qty;
        $this->writeRaw($raw);
    }

    public function clear(): void
    {
        $this->writeRaw([]);
    }

    /**
     * Turns the cart into a real, persisted draft SalesOrder + rows — deliberately the only place
     * this happens, and only when the member is actually ready to pay, so an abandoned browse never
     * leaves a draft order behind. Clears the cart once done; the order is the source of truth from here.
     *
     * @throws \InvalidArgumentException if the cart is empty
     */
    public function checkout(User $user): SalesOrder
    {
        $lines = $this->getLines($user);
        if ($lines === []) {
            throw new \InvalidArgumentException('Your cart is empty.');
        }

        $order = new SalesOrder();
        $order->setUser($user);

        foreach ($lines as $line) {
            $row = new SalesOrderRow();
            $row->setProduct($line['product']);
            $row->setQty($line['qty']);
            $row->setListPriceAtSale($line['product']->getPrice());
            $row->setChargedPrice($line['product']->getPrice());
            $row->setVatCodeAtSale($line['product']->getVatCode());
            $row->setOccurrenceDate($line['occurrenceDate']);
            $row->setBeneficiaryMember($line['beneficiary'] === $user ? null : $line['beneficiary']);
            $order->addRow($row);
            $this->em->persist($row);
        }

        $this->em->persist($order);
        $this->em->flush();

        $this->clear();

        return $order;
    }

    /** @return array<int, User> keyed by id — the member themself plus their dependents, the only people they can buy for here. */
    private function eligibleBeneficiaries(User $user): array
    {
        $map = [$user->getId() => $user];
        foreach ($user->getDependents() as $dependent) {
            $map[$dependent->getId()] = $dependent;
        }
        return $map;
    }

    /**
     * A restricted event only ever allows a single ticket per line — each seat needs its own
     * certification check, so it can't be anonymous — but an unrestricted event has no such
     * requirement, so one line can cover several seats bought by the same person (e.g. 3 places
     * on an open film night). Any event with a capacity cap also can't be oversold beyond its
     * remaining spots for that occurrence, checked here so it applies whether qty is 1 or more.
     */
    private function assertQtyAllowed(Product $product, int $qty, ?\DateTimeImmutable $occurrenceDate): void
    {
        if ($qty < 1) {
            throw new \InvalidArgumentException('Choose at least one.');
        }

        $eventTicketProduct = $product->getEventTicketProduct();
        if ($eventTicketProduct === null) {
            if ($qty > 1) {
                throw new \InvalidArgumentException('That can only be bought one at a time.');
            }
            return;
        }

        $event = $eventTicketProduct->getEvent();

        if ($qty > 1 && !$event->getRestrictions()->isEmpty()) {
            throw new \InvalidArgumentException('Only one ticket at a time can be booked for a restricted event.');
        }

        if ($event->getMaxAttendees() !== null) {
            $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;
            $spotsLeft = max(0, $event->getMaxAttendees() - $this->attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate));

            if ($qty > $spotsLeft) {
                throw new \InvalidArgumentException($spotsLeft > 0 ? "Only {$spotsLeft} place(s) left for this event." : 'Sorry, this event is fully booked.');
            }
        }
    }

    /**
     * Parses a stored/submitted "Y-m-d" string to midnight on that date. createFromFormat() alone
     * would leave the *current* time of day on whatever fields the format doesn't specify — so two
     * lines for the same calendar date, parsed a few minutes apart, would silently fail a later `==`
     * comparison (e.g. against a controller-parsed date, which defaults to midnight). setTime(0, 0)
     * keeps every occurrence date comparable regardless of when it was parsed.
     */
    private function parseOccurrenceDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return $parsed !== false ? $parsed->setTime(0, 0) : null;
    }

    private function lineKey(int $productId, int $beneficiaryId, ?\DateTimeImmutable $occurrenceDate): string
    {
        return $productId . ':' . $beneficiaryId . ':' . ($occurrenceDate?->format('Y-m-d') ?? '');
    }

    private function readRaw(): array
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY, []);
    }

    private function writeRaw(array $raw): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $raw);
    }
}
