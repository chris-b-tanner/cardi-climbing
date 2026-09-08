<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Note;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderRow;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\NoteRepository;
use App\Repository\ProductRepository;
use App\Repository\SalesOrderRepository;
use App\Repository\UserRepository;
use App\Service\SalesOrderService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Point-of-sale — building and completing a member's SalesOrder. */
#[Route('/admin/sales')]
#[IsGranted('ROLE_TEAM')]
class AdminSalesController extends AbstractController
{
    #[Route('', name: 'app_admin_sales')]
    public function index(Request $request, SalesOrderRepository $salesOrderRepository): Response
    {
        $query  = trim($request->query->get('q', ''));
        $orders = $salesOrderRepository->search($query);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/sales/_list.html.twig', [
                'orders' => $orders,
            ]);
        }

        return $this->render('admin/sales/index.html.twig', [
            'orders'       => $orders,
            'currentQuery' => $query,
        ]);
    }

    /** Starts a new draft order for {userId} — landed on from the members list's "new sale" context. */
    #[Route('/new/{userId}', name: 'app_admin_sale_new', requirements: ['userId' => '\d+'], methods: ['POST'])]
    public function new(Request $request, int $userId, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        $user = $userRepository->find($userId);
        if (!$user instanceof User) {
            $this->addFlash('error', 'Member not found.');
            return $this->redirectToRoute('app_admin_users', ['context' => 'new_sale']);
        }

        if (!$this->isCsrfTokenValid('admin_sale_new_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_admin_users', ['context' => 'new_sale']);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $order = new SalesOrder();
        $order->setUser($user);
        $order->setCreatedBy($admin);

        $em->persist($order);
        $em->flush();

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}', name: 'app_admin_sale_show', requirements: ['id' => '\d+'])]
    public function show(SalesOrder $order, ProductRepository $productRepository, NoteRepository $noteRepository): Response
    {
        return $this->render('admin/sales/show.html.twig', [
            'order'    => $order,
            'products' => $productRepository->findActive(),
            'notes'    => $noteRepository->findForNoteable(Note::TYPE_ORDER, $order->getId()),
        ]);
    }

    /** Only a still-open draft can be deleted — a completed or cancelled order is kept as a financial record even if it has no payments (e.g. a free order). */
    #[Route('/{id}/delete', name: 'app_admin_sale_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, SalesOrder $order, EntityManagerInterface $em, NoteRepository $noteRepository): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $pinnedCount = $noteRepository->countPinnedFor(Note::TYPE_ORDER, $order->getId());
        if ($pinnedCount > 0) {
            $this->addFlash('error', "Unpin {$pinnedCount} pinned note(s) before deleting this record.");
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        foreach ($noteRepository->findForNoteable(Note::TYPE_ORDER, $order->getId()) as $note) {
            $em->remove($note);
        }

        $em->remove($order);
        $em->flush();

        $this->addFlash('success', 'Order deleted.');
        return $this->redirectToRoute('app_admin_sales');
    }

    #[Route('/{id}/rows', name: 'app_admin_sale_add_row', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addRow(Request $request, SalesOrder $order, ProductRepository $productRepository, SalesOrderService $salesOrderService, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $product = $productRepository->find((int) $request->request->get('productId', 0));
        if (!$product instanceof Product || !$product->isActive()) {
            $this->addFlash('error', 'Choose a valid product.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $occurrenceDate = null;
        $eventTicketProduct = $product->getEventTicketProduct();
        if ($eventTicketProduct !== null && $eventTicketProduct->getEvent()->isRecurring()) {
            $event = $eventTicketProduct->getEvent();
            $raw   = trim($request->request->get('occurrenceDate', ''));
            // setTime(0, 0): createFromFormat() alone leaves the *current* time of day on fields
            // the format doesn't specify, which would silently break the == comparison against
            // existing rows' occurrenceDate (always midnight, via Doctrine's date_immutable column).
            $parsedOccurrenceDate = $raw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $raw) : false;
            $occurrenceDate = $parsedOccurrenceDate !== false ? $parsedOccurrenceDate->setTime(0, 0) : null;

            if ($occurrenceDate === null || !$event->isValidForDate($occurrenceDate)) {
                $this->addFlash('error', 'Choose a valid date for this event.');
                return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
            }
        }

        if ($product->requiresBeneficiary()) {
            // One line per beneficiary (and occurrence, for an event ticket) — never merge qty here.
            // New rows always default to the order's own member; the only way to free up this slot
            // for another line is changing an existing row's beneficiary away from the order's member.
            if ($salesOrderService->wouldConflictWithExisting($product, $occurrenceDate, $order->getUser(), $this->existingLines($order))) {
                $this->addFlash('error', 'This member already has a line for this product. Change its beneficiary first if you want to add another.');
                return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
            }

            $row = new SalesOrderRow();
            $row->setProduct($product);
            $row->setQty(1);
            $row->setListPriceAtSale($product->getPrice());
            $row->setChargedPrice($product->getPrice());
            $row->setVatCodeAtSale($product->getVatCode());
            $row->setOccurrenceDate($occurrenceDate);
            $order->addRow($row);
            $em->persist($row);
        } else {
            // Stock/service — repeat adds just bump the quantity on the existing line.
            $existing = null;
            foreach ($order->getRows() as $candidate) {
                if ($candidate->getProduct() === $product && $candidate->getBeneficiaryMember() === null) {
                    $existing = $candidate;
                    break;
                }
            }

            if ($existing !== null) {
                $existing->setQty($existing->getQty() + 1);
            } else {
                $row = new SalesOrderRow();
                $row->setProduct($product);
                $row->setQty(1);
                $row->setListPriceAtSale($product->getPrice());
                $row->setChargedPrice($product->getPrice());
                $row->setVatCodeAtSale($product->getVatCode());
                $order->addRow($row);
                $em->persist($row);
            }
        }

        $em->flush();

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    /** Valid upcoming occurrence dates for a recurring event, for the "choose a date" calendar modal on the product tile. */
    #[Route('/occurrences', name: 'app_admin_sale_occurrences')]
    public function occurrences(Request $request, EventRepository $eventRepository): JsonResponse
    {
        $event = $eventRepository->find((int) $request->query->get('eventId', 0));
        if (!$event instanceof Event) {
            return $this->json(['error' => 'Event not found.'], 404);
        }

        $year  = (int) $request->query->get('year', (int) date('Y'));
        $month = (int) $request->query->get('month', (int) date('n'));
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12 + 12) % 12 + 1;

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd   = $monthStart->modify('last day of this month');
        $today      = new \DateTimeImmutable('today');

        $occurrences = [];
        $period = new \DatePeriod($monthStart, new \DateInterval('P1D'), $monthEnd->modify('+1 day'));
        foreach ($period as $day) {
            if ($day >= $today && $event->isValidForDate($day)) {
                $occurrences[] = $day->format('Y-m-d');
            }
        }

        return $this->json([
            'monthLabel'  => $monthStart->format('F Y'),
            'year'        => (int) $year,
            'month'       => $month,
            'prevYear'    => (int) $monthStart->modify('-1 month')->format('Y'),
            'prevMonth'   => (int) $monthStart->modify('-1 month')->format('n'),
            'nextYear'    => (int) $monthStart->modify('+1 month')->format('Y'),
            'nextMonth'   => (int) $monthStart->modify('+1 month')->format('n'),
            'occurrences' => $occurrences,
        ]);
    }

    #[Route('/{id}/rows/{rowId}/increase', name: 'app_admin_sale_row_increase', requirements: ['id' => '\d+', 'rowId' => '\d+'], methods: ['POST'])]
    public function increaseRow(Request $request, SalesOrder $order, int $rowId, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, $rowId);
        if ($row->getProduct()->requiresBeneficiary()) {
            $this->addFlash('error', 'This line can only have a quantity of 1 — add another line for a different beneficiary instead.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row->setQty($row->getQty() + 1);
        $em->flush();

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/rows/{rowId}/decrease', name: 'app_admin_sale_row_decrease', requirements: ['id' => '\d+', 'rowId' => '\d+'], methods: ['POST'])]
    public function decreaseRow(Request $request, SalesOrder $order, int $rowId, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, $rowId);
        if ($row->getQty() <= 1) {
            $order->removeRow($row);
            $em->remove($row);
        } else {
            $row->setQty($row->getQty() - 1);
        }
        $em->flush();

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/rows/{rowId}/remove', name: 'app_admin_sale_row_remove', requirements: ['id' => '\d+', 'rowId' => '\d+'], methods: ['POST'])]
    public function removeRow(Request $request, SalesOrder $order, int $rowId, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, $rowId);
        $order->removeRow($row);
        $em->remove($row);
        $em->flush();

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    /** Members for the "change beneficiary" picker — the order member's dependents by default, or a general search once a query is typed. */
    #[Route('/{id}/beneficiary-search', name: 'app_admin_sale_beneficiary_search', requirements: ['id' => '\d+'])]
    public function beneficiarySearch(Request $request, SalesOrder $order, UserRepository $userRepository): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if ($query === '') {
            $candidates = $order->getUser()->getDependents()->toArray();
        } elseif (mb_strlen($query) < 2) {
            $candidates = [];
        } else {
            $candidates = $userRepository->search($query, null, 20);
        }

        return $this->json(array_map(function (User $candidate) {
            $name = $candidate->getDisplayName();

            return [
                'id'    => $candidate->getId(),
                'label' => $candidate->getEmail() ? $name . ' — ' . $candidate->getEmail() : $name,
            ];
        }, $candidates));
    }

    /** Points a row at an existing member as its beneficiary. */
    #[Route('/{id}/beneficiary', name: 'app_admin_sale_set_beneficiary', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setBeneficiary(Request $request, SalesOrder $order, UserRepository $userRepository, SalesOrderService $salesOrderService, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, (int) $request->request->get('rowId', 0));
        if (!$row->getProduct()->requiresBeneficiary()) {
            $this->addFlash('error', 'This line has no beneficiary to change.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $beneficiary = $userRepository->find((int) $request->request->get('userId', 0));
        if (!$beneficiary instanceof User) {
            $this->addFlash('error', 'Choose a member.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        if ($salesOrderService->wouldConflictWithExisting($row->getProduct(), $row->getOccurrenceDate(), $beneficiary, $this->existingLines($order, $row))) {
            $this->addFlash('error', 'That member already has a line for this product.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        // null is the canonical "same as the order's member" — store it that way rather than an explicit self-reference.
        $row->setBeneficiaryMember($beneficiary === $order->getUser() ? null : $beneficiary);
        $em->flush();

        $this->addFlash('success', 'Beneficiary updated.');
        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    /** A note is mandatory here — an overridden price always needs a recorded reason, unlike the row's note in general. */
    #[Route('/{id}/rows/price', name: 'app_admin_sale_row_price', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateRowPrice(Request $request, SalesOrder $order, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, (int) $request->request->get('rowId', 0));

        $priceRaw = trim($request->request->get('price', ''));
        $note     = trim($request->request->get('note', ''));

        if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
            $this->addFlash('error', 'Enter a valid price.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        if ($note === '') {
            $this->addFlash('error', 'A note is required when changing the price.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row->setChargedPrice(number_format((float) $priceRaw, 2, '.', ''));
        $row->setNote($note);
        $em->flush();

        $this->addFlash('success', 'Price updated.');
        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    /** Creates a new member on the fly (optionally as the order member's dependent) and uses them as a row's beneficiary. */
    #[Route('/{id}/beneficiary/new', name: 'app_admin_sale_create_beneficiary', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createBeneficiary(Request $request, SalesOrder $order, UserService $userService, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, (int) $request->request->get('rowId', 0));
        if (!$row->getProduct()->requiresBeneficiary()) {
            $this->addFlash('error', 'This line has no beneficiary to change.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $firstName     = trim($request->request->get('firstName', ''));
        $lastName      = trim($request->request->get('lastName', ''));
        $email         = trim($request->request->get('email', '')) ?: null;
        $dateOfBirthRaw = trim($request->request->get('dateOfBirth', ''));
        $dateOfBirth   = $dateOfBirthRaw !== '' ? (\DateTimeImmutable::createFromFormat('Y-m-d', $dateOfBirthRaw) ?: null) : null;

        if ($firstName === '') {
            $this->addFlash('error', 'First name is required.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        if ($email !== null && $userService->findExistingByEmail($email)) {
            $this->addFlash('error', 'A member with that email address already exists.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $beneficiary = $userService->createContact(
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            noteContent: 'Added as a beneficiary on Sale #' . $order->getId() . ' by ' . $admin->getDisplayName() . '.',
            addedBy: $admin,
            parent: $request->request->has('makeDependent') ? $order->getUser() : null,
            dateOfBirth: $dateOfBirth,
        );

        $row->setBeneficiaryMember($beneficiary);
        $em->flush();

        $this->addFlash('success', 'New member created and set as beneficiary.');
        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/complete-free', name: 'app_admin_sale_complete_free', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function completeFree(Request $request, SalesOrder $order, SalesOrderService $salesOrderService): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        if ($order->getTotal() !== '0.00') {
            $this->addFlash('error', 'This order has a balance — choose a payment method.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        try {
            $salesOrderService->completeFree($order);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $this->addFlash('success', 'Order completed.');

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/complete-cash', name: 'app_admin_sale_complete_cash', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function completeCash(Request $request, SalesOrder $order, SalesOrderService $salesOrderService): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $salesOrderService->completeWithCash($order, $admin);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $this->addFlash('success', 'Order completed — cash payment recorded.');

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    /** Records a pending card payment against the order. Sending it to the card machine itself isn't wired up yet — completeFromPayment() (via the Stripe webhook) finishes the job once that's confirmed. */
    #[Route('/{id}/pay-card', name: 'app_admin_sale_pay_card', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function payCard(Request $request, SalesOrder $order, SalesOrderService $salesOrderService): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $salesOrderService->startCardPayment($order, $admin);
        $this->addFlash('success', 'Card payment started — waiting for confirmation.');

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    private function assertOpenAndValid(Request $request, SalesOrder $order): bool
    {
        if (!$this->isCsrfTokenValid('admin_sale_' . $order->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return false;
        }

        if ($order->getStatus() !== SalesOrder::STATUS_DRAFT) {
            $this->addFlash('error', 'This order is no longer open.');
            return false;
        }

        return true;
    }

    private function getOwnedRow(SalesOrder $order, int $rowId): SalesOrderRow
    {
        foreach ($order->getRows() as $row) {
            if ($row->getId() === $rowId) {
                return $row;
            }
        }

        throw $this->createNotFoundException('Row not found.');
    }

    /** Whether some other row in the order already has this exact (product, occurrence, beneficiary) combination. */
    /** @return array<array{0: Product, 1: ?\DateTimeImmutable, 2: User}> */
    private function existingLines(SalesOrder $order, ?SalesOrderRow $exclude = null): array
    {
        $lines = [];
        foreach ($order->getRows() as $row) {
            if ($row === $exclude) {
                continue;
            }
            $lines[] = [$row->getProduct(), $row->getOccurrenceDate(), $row->getEffectiveBeneficiary()];
        }
        return $lines;
    }
}
