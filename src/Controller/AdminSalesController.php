<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderRow;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\SalesOrderRepository;
use App\Repository\UserRepository;
use App\Service\SalesOrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function index(SalesOrderRepository $salesOrderRepository): Response
    {
        return $this->render('admin/sales/index.html.twig', [
            'orders' => $salesOrderRepository->findAllOrdered(),
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
    public function show(SalesOrder $order, ProductRepository $productRepository): Response
    {
        return $this->render('admin/sales/show.html.twig', [
            'order'    => $order,
            'products' => $productRepository->findActive(),
        ]);
    }

    #[Route('/{id}/rows', name: 'app_admin_sale_add_row', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addRow(Request $request, SalesOrder $order, ProductRepository $productRepository, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $product = $productRepository->find((int) $request->request->get('productId', 0));
        if (!$product instanceof Product || !$product->isActive()) {
            $this->addFlash('error', 'Choose a valid product.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $eventTicketProduct = $product->getEventTicketProduct();
        if ($eventTicketProduct !== null && $eventTicketProduct->getEvent()->isRecurring()) {
            $this->addFlash('error', 'That event repeats — booking a specific occurrence isn\'t supported here yet.');
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

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

        $em->flush();

        return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/rows/{rowId}/increase', name: 'app_admin_sale_row_increase', requirements: ['id' => '\d+', 'rowId' => '\d+'], methods: ['POST'])]
    public function increaseRow(Request $request, SalesOrder $order, int $rowId, EntityManagerInterface $em): Response
    {
        if (!$this->assertOpenAndValid($request, $order)) {
            return $this->redirectToRoute('app_admin_sale_show', ['id' => $order->getId()]);
        }

        $row = $this->getOwnedRow($order, $rowId);
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

        $salesOrderService->completeFree($order);
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

        $salesOrderService->completeWithCash($order, $admin);
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
}
