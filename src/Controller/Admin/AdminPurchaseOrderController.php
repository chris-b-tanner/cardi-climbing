<?php

namespace App\Controller\Admin;

use App\Entity\Note;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\ProductRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\UserRepository;
use App\Service\Mailer\PurchaseOrderMailer;
use App\Service\PurchaseOrderInvoiceUploader;
use App\Service\PurchaseOrderPdfGenerator;
use App\Service\PurchaseOrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Purchasing — raising, approving, placing and receiving purchase orders (see
 * purchase-orders-spec.md). The team can raise and edit drafts, submit them, receive deliveries and
 * upload invoices; approving, rejecting, placing, cancelling and changing an approved line's cost
 * are admin-only. Status changes go through PurchaseOrderService.
 */
#[Route('/admin/purchasing')]
#[IsGranted('ROLE_TEAM')]
class AdminPurchaseOrderController extends AbstractController
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrderRepository,
        private readonly PurchaseOrderService $service,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('', name: 'app_admin_purchase_orders')]
    public function index(Request $request): Response
    {
        $filters = $this->parseListFilters($request);

        $params = [
            'purchaseOrders' => $this->purchaseOrderRepository->search($filters['tab'], $filters['q'], $filters['supplier'], $filters['from'], $filters['to']),
            'filters'        => $filters,
            'suppliers'      => $this->userRepository->findSuppliers(),
            'awaitingCount'  => $this->purchaseOrderRepository->countAwaitingApproval(),
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/purchase_orders/_list.html.twig', $params);
        }

        return $this->render('admin/purchase_orders/index.html.twig', $params);
    }

    #[Route('/new', name: 'app_admin_purchase_order_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $suppliers = $this->userRepository->findSuppliers();
        $error     = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_purchase_order_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $po       = new PurchaseOrder();
            $supplier = $this->userRepository->find((int) $request->request->get('supplierId', 0));

            if (!$supplier || !$supplier->isSupplier() || $supplier->isDeleted()) {
                $error = 'Choose a supplier.';
            } else {
                $po->setSupplier($supplier);
                $error = $this->applyDetails($request, $po, allFields: true);
            }

            if (!$error) {
                /** @var User $user */
                $user = $this->getUser();
                $this->service->create($po, $user);

                $this->addFlash('success', $po->getNumber() . ' created — now add what you\'re ordering.');
                return $this->redirectToRoute('app_admin_purchase_order_show', ['id' => $po->getId()]);
            }
        }

        return $this->render('admin/purchase_orders/new.html.twig', [
            'suppliers'          => $suppliers,
            'error'              => $error,
            'selectedSupplierId' => (int) $request->query->get('supplierId', 0),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_purchase_order_show', requirements: ['id' => '\d+'])]
    public function show(PurchaseOrder $po, NoteRepository $noteRepository, ProductRepository $productRepository): Response
    {
        return $this->render('admin/purchase_orders/show.html.twig', [
            'po'            => $po,
            'notes'         => $noteRepository->findForNoteable(Note::TYPE_PURCHASE_ORDER, $po->getId()),
            'stockProducts' => $po->isEditable() ? $productRepository->search('', Product::TYPE_STOCK) : [],
            'suppliers'     => $po->isEditable() ? $this->userRepository->findSuppliers() : [],
        ]);
    }

    /**
     * Header details. The supplier's reference and the expected date can be corrected at any point
     * until the order's cancelled (both are often only known once it's placed); the supplier and
     * delivery charge only while it's a draft.
     */
    #[Route('/{id}/details', name: 'app_admin_purchase_order_details', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function details(Request $request, PurchaseOrder $po, EntityManagerInterface $em): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_details_' . $po->getId())) {
            return $this->redirectToShow($po);
        }
        if ($po->getStatus() === PurchaseOrder::STATUS_CANCELLED) {
            $this->addFlash('error', 'This order has been cancelled.');
            return $this->redirectToShow($po);
        }

        $error = $this->applyDetails($request, $po, allFields: $po->isEditable());
        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToShow($po);
        }

        $em->flush();
        $this->addFlash('success', 'Details updated.');
        return $this->redirectToShow($po);
    }

    #[Route('/{id}/lines/add', name: 'app_admin_purchase_order_line_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addLine(Request $request, PurchaseOrder $po, ProductRepository $productRepository, EntityManagerInterface $em): Response
    {
        if (!$this->assertEditable($request, $po)) {
            return $this->redirectToShow($po);
        }

        $line = new PurchaseOrderLine();

        if ($request->request->get('kind') === 'stock') {
            $product      = $productRepository->find((int) $request->request->get('productId', 0));
            $stockProduct = $product?->getStockProduct();
            if (!$stockProduct) {
                $this->addFlash('error', 'Choose a stock product.');
                return $this->redirectToShow($po);
            }

            $line->setStockProduct($stockProduct);
            $line->setDescription(mb_substr($product->getName() . ($product->getVariantValue() ? ' (' . $product->getVariantValue() . ')' : ''), 0, 255));
            $line->setUnitCost($stockProduct->getCostPrice());
            $line->setVatCode($product->getVatCode());
        } else {
            $line->setCategory(PurchaseOrderLine::CATEGORY_OTHER);
        }

        $error = $this->applyLine($request, $line, isNew: true);
        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToShow($po);
        }

        $po->addLine($line);
        $em->persist($line);
        $em->flush();

        $this->addFlash('success', 'Line added.');
        return $this->redirectToShow($po);
    }

    #[Route('/{id}/lines/{lineId}/update', name: 'app_admin_purchase_order_line_update', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    public function updateLine(Request $request, PurchaseOrder $po, int $lineId, EntityManagerInterface $em): Response
    {
        if (!$this->assertEditable($request, $po)) {
            return $this->redirectToShow($po);
        }

        $line = $po->findLine($lineId) ?? throw $this->createNotFoundException();

        $error = $this->applyLine($request, $line, isNew: false);
        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToShow($po);
        }

        $em->flush();
        $this->addFlash('success', 'Line updated.');
        return $this->redirectToShow($po);
    }

    #[Route('/{id}/lines/{lineId}/remove', name: 'app_admin_purchase_order_line_remove', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    public function removeLine(Request $request, PurchaseOrder $po, int $lineId, EntityManagerInterface $em): Response
    {
        if (!$this->assertEditable($request, $po)) {
            return $this->redirectToShow($po);
        }

        $line = $po->findLine($lineId) ?? throw $this->createNotFoundException();
        $po->removeLine($line);
        $em->flush();

        $this->addFlash('success', 'Line removed.');
        return $this->redirectToShow($po);
    }

    #[Route('/{id}/submit', name: 'app_admin_purchase_order_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(Request $request, PurchaseOrder $po): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_submit_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        /** @var User $user */
        $user       = $this->getUser();
        $approveToo = (bool) $request->request->get('approve');

        return $this->attempt($po, function () use ($po, $user, $approveToo) {
            // "Submit and approve" — only on an admin's own draft, so nobody approves someone else's without it being submitted first.
            if ($approveToo && $this->isGranted('ROLE_ADMIN') && $po->getCreatedBy() === $user) {
                $this->service->submitAndApprove($po, $user);
                return 'Submitted and approved.';
            }
            $this->service->submit($po, $user);
            return 'Submitted for approval.';
        });
    }

    /** Back to draft — by whoever raised it, or an admin. */
    #[Route('/{id}/pull-back', name: 'app_admin_purchase_order_pull_back', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pullBack(Request $request, PurchaseOrder $po): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_pull_back_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($po->getCreatedBy() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'Only the person who raised this order can pull it back.');
            return $this->redirectToShow($po);
        }

        return $this->attempt($po, function () use ($po, $user) {
            $this->service->pullBack($po, $user);
            return 'Pulled back to draft.';
        });
    }

    #[Route('/{id}/approve', name: 'app_admin_purchase_order_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(Request $request, PurchaseOrder $po): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_approve_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->attempt($po, function () use ($po, $admin) {
            $this->service->approve($po, $admin);
            return 'Approved.';
        });
    }

    #[Route('/{id}/reject', name: 'app_admin_purchase_order_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reject(Request $request, PurchaseOrder $po): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_reject_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        $reason = trim($request->request->get('reason', ''));
        if ($reason === '') {
            $this->addFlash('error', 'Say why it\'s being rejected — it goes to whoever raised it.');
            return $this->redirectToShow($po);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->attempt($po, function () use ($po, $admin, $reason) {
            $this->service->reject($po, $admin, $reason);
            return 'Rejected and sent back to draft.';
        });
    }

    #[Route('/{id}/mark-ordered', name: 'app_admin_purchase_order_mark_ordered', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function markOrdered(Request $request, PurchaseOrder $po, EntityManagerInterface $em): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_mark_ordered_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        $error = $this->applyDetails($request, $po, allFields: false);
        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToShow($po);
        }

        $how = trim($request->request->get('how', ''));

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->attempt($po, function () use ($po, $admin, $how) {
            $this->service->markOrdered($po, $admin, $how !== '' ? '(' . mb_substr($how, 0, 100) . ')' : null);
            return 'Marked as ordered.';
        });
    }

    /** Emails the PDF to the supplier and marks the order placed. Can be re-sent once ordered (e.g. it went to the wrong person) without changing anything else. */
    #[Route('/{id}/email', name: 'app_admin_purchase_order_email', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function email(Request $request, PurchaseOrder $po, PurchaseOrderPdfGenerator $pdfGenerator, PurchaseOrderMailer $mailer, EntityManagerInterface $em): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_email_' . $po->getId())) {
            return $this->redirectToShow($po);
        }
        if (!in_array($po->getStatus(), [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_ORDERED], true)) {
            $this->addFlash('error', 'Only an approved order can be emailed to the supplier.');
            return $this->redirectToShow($po);
        }

        $message = trim($request->request->get('message', ''));

        /** @var User $admin */
        $admin = $this->getUser();

        try {
            $sentTo = $mailer->sendToSupplier($po, $pdfGenerator->generate($po), $pdfGenerator->filename($po), $message !== '' ? $message : null, $admin);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToShow($po);
        } catch (TransportExceptionInterface) {
            $this->addFlash('error', 'The email couldn\'t be sent — please try again shortly.');
            return $this->redirectToShow($po);
        }

        if ($po->getStatus() === PurchaseOrder::STATUS_APPROVED) {
            $this->service->markOrdered($po, $admin, '(emailed to ' . $sentTo . ')');
        } else {
            $this->service->record($po, 'Emailed to ' . $sentTo . ' again', $admin);
            $em->flush();
        }

        $this->addFlash('success', 'Emailed to ' . $sentTo . '.');
        return $this->redirectToShow($po);
    }

    #[Route('/{id}/cancel', name: 'app_admin_purchase_order_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancel(Request $request, PurchaseOrder $po): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_cancel_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        $reason = trim($request->request->get('reason', ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->addFlash('error', 'Give a reason for cancelling (under 255 characters).');
            return $this->redirectToShow($po);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->attempt($po, function () use ($po, $admin, $reason) {
            $this->service->cancel($po, $admin, $reason);
            return 'Order cancelled.';
        });
    }

    /** An admin corrects an approved line's unit cost, with a required reason — until that line has been received. */
    #[Route('/{id}/lines/{lineId}/cost', name: 'app_admin_purchase_order_line_cost', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function lineCost(Request $request, PurchaseOrder $po, int $lineId): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_line_cost_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        $line = $po->findLine($lineId) ?? throw $this->createNotFoundException();

        [$unitCost, $error] = $this->parseMoney($request->request->get('unitCost', ''), 'unit cost');
        $reason = trim($request->request->get('reason', ''));
        if ($error || $unitCost === null) {
            $this->addFlash('error', $error ?? 'Enter the new unit cost.');
            return $this->redirectToShow($po);
        }
        if ($reason === '') {
            $this->addFlash('error', 'A reason is required when changing an approved price.');
            return $this->redirectToShow($po);
        }

        /** @var User $admin */
        $admin = $this->getUser();

        return $this->attempt($po, function () use ($line, $unitCost, $reason, $admin) {
            $this->service->changeLineCost($line, $unitCost, $reason, $admin);
            return 'Unit cost updated.';
        });
    }

    #[Route('/{id}/receive', name: 'app_admin_purchase_order_receive', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function receive(Request $request, PurchaseOrder $po): Response
    {
        if (!$po->isAwaitingDelivery()) {
            $this->addFlash('error', 'Deliveries can only be recorded once the order has been placed.');
            return $this->redirectToShow($po);
        }

        if ($request->isMethod('POST')) {
            if (!$this->assertCsrf($request, 'admin_po_receive_' . $po->getId())) {
                return $this->redirectToShow($po);
            }

            $quantities = [];
            foreach ($request->request->all('qty') as $lineId => $raw) {
                $raw = trim((string) $raw);
                if ($raw === '') {
                    continue;
                }
                if (!ctype_digit($raw)) {
                    $this->addFlash('error', 'Quantities must be whole numbers.');
                    return $this->redirectToRoute('app_admin_purchase_order_receive', ['id' => $po->getId()]);
                }
                $quantities[(int) $lineId] = (int) $raw;
            }

            $receivedAt = $this->parseDate($request->request->get('receivedAt', ''));
            $receivedAt = $receivedAt === null ? new \DateTimeImmutable()
                : ($receivedAt->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d') ? new \DateTimeImmutable() : $receivedAt->setTime(12, 0));

            $note = trim($request->request->get('note', ''));

            /** @var User $user */
            $user = $this->getUser();

            try {
                $count = $this->service->receive(
                    $po,
                    $quantities,
                    array_map('intval', $request->request->all('asset')),
                    array_map('intval', $request->request->all('individual')),
                    $receivedAt,
                    $note !== '' ? mb_substr($note, 0, 255) : null,
                    $user,
                );
            } catch (\LogicException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_admin_purchase_order_receive', ['id' => $po->getId()]);
            }

            $this->addFlash('success', 'Delivery recorded for ' . $count . ' line' . ($count === 1 ? '' : 's') . '.');
            return $this->redirectToShow($po);
        }

        return $this->render('admin/purchase_orders/receive.html.twig', ['po' => $po]);
    }

    #[Route('/{id}/pdf', name: 'app_admin_purchase_order_pdf', requirements: ['id' => '\d+'])]
    public function pdf(PurchaseOrder $po, PurchaseOrderPdfGenerator $pdfGenerator): Response
    {
        return new Response($pdfGenerator->generate($po), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdfGenerator->filename($po) . '"',
        ]);
    }

    #[Route('/{id}/invoices/upload', name: 'app_admin_purchase_order_invoice_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadInvoice(Request $request, PurchaseOrder $po, PurchaseOrderInvoiceUploader $uploader, EntityManagerInterface $em): Response
    {
        if (!$this->assertCsrf($request, 'admin_po_invoice_upload_' . $po->getId())) {
            return $this->redirectToShow($po);
        }

        $file = $request->files->get('file');
        if (!$file) {
            $this->addFlash('error', 'Please choose a file to upload.');
            return $this->redirectToShow($po);
        }

        $number  = trim($request->request->get('invoiceNumber', ''));
        $dateRaw = trim($request->request->get('invoiceDate', ''));
        $date    = $this->parseDate($dateRaw);
        if ($dateRaw !== '' && $date === null) {
            $this->addFlash('error', 'Enter a valid invoice date.');
            return $this->redirectToShow($po);
        }

        /** @var User $user */
        $user   = $this->getUser();
        $result = $uploader->upload($po, $file, $number !== '' ? mb_substr($number, 0, 100) : null, $date, $user);

        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToShow($po);
        }

        $em->persist($result);
        $this->service->record($po, 'Invoice uploaded' . ($result->getInvoiceNumber() ? ': ' . $result->getInvoiceNumber() : ''), $user);
        $em->flush();

        $this->addFlash('success', 'Invoice uploaded.');
        return $this->redirectToShow($po);
    }

    #[Route('/{id}/invoices/{invoiceId}/download', name: 'app_admin_purchase_order_invoice_download', requirements: ['id' => '\d+', 'invoiceId' => '\d+'])]
    public function downloadInvoice(PurchaseOrder $po, int $invoiceId, PurchaseOrderInvoiceUploader $uploader): Response
    {
        $invoice = $po->findInvoice($invoiceId) ?? throw $this->createNotFoundException();

        return $this->redirect($uploader->getDownloadUrl($invoice));
    }

    #[Route('/{id}/invoices/{invoiceId}/delete', name: 'app_admin_purchase_order_invoice_delete', requirements: ['id' => '\d+', 'invoiceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteInvoice(Request $request, PurchaseOrder $po, int $invoiceId, PurchaseOrderInvoiceUploader $uploader, EntityManagerInterface $em): Response
    {
        $invoice = $po->findInvoice($invoiceId) ?? throw $this->createNotFoundException();

        if (!$this->assertCsrf($request, 'admin_po_invoice_delete_' . $invoice->getId())) {
            return $this->redirectToShow($po);
        }

        $uploader->remove($invoice);
        $po->getInvoices()->removeElement($invoice);
        $em->remove($invoice);

        /** @var User $admin */
        $admin = $this->getUser();
        $this->service->record($po, 'Invoice removed: ' . ($invoice->getInvoiceNumber() ?? $invoice->getOriginalFilename()), $admin);
        $em->flush();

        $this->addFlash('success', 'Invoice removed.');
        return $this->redirectToShow($po);
    }

    /** Spend by category, supplier and month, counted from each PO's approval date. */
    #[Route('/spend', name: 'app_admin_purchase_orders_spend')]
    public function spend(Request $request): Response
    {
        [$from, $to, $supplierId] = $this->parseSpendFilters($request);

        return $this->render('admin/purchase_orders/spend.html.twig', [
            'report'    => $this->buildSpendReport($this->purchaseOrderRepository->findForSpend($from, $to, $supplierId)),
            'from'      => $from,
            'to'        => $to,
            'supplierId' => $supplierId,
            'suppliers' => $this->userRepository->findSuppliers(),
        ]);
    }

    /** One CSV row per line (plus one per delivery charge), with its PO, supplier, month and category, for the accounts. */
    #[Route('/spend/export', name: 'app_admin_purchase_orders_spend_export')]
    public function spendExport(Request $request): StreamedResponse
    {
        [$from, $to, $supplierId] = $this->parseSpendFilters($request);
        $purchaseOrders = $this->purchaseOrderRepository->findForSpend($from, $to, $supplierId);

        $response = new StreamedResponse(function () use ($purchaseOrders) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['PO', 'Approved', 'Supplier', 'Category', 'Description', 'Qty', 'Unit cost (net)', 'Net', 'VAT', 'Gross', 'Status']);

            foreach ($purchaseOrders as $po) {
                $common = [$po->getNumber(), $po->getApprovedAt()?->format('Y-m-d'), $po->getSupplier()->getSupplierName()];

                foreach ($po->getLines() as $line) {
                    fputcsv($handle, [...$common, $line->getCategoryLabel(), $line->getDescription(), $line->getQty(), $line->getUnitCost(), $line->getNetTotal(), $line->getVat(), $line->getGrossTotal(), $po->getStatusLabel()]);
                }

                if ((float) $po->getDeliveryCharge() > 0) {
                    $vat = $po->getDeliveryVat();
                    fputcsv($handle, [...$common, 'Delivery', 'Delivery', 1, $po->getDeliveryCharge(), $po->getDeliveryCharge(), $vat, number_format((float) $po->getDeliveryCharge() + (float) $vat, 2, '.', ''), $po->getStatusLabel()]);
                }
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="purchasing-spend-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv"');

        return $response;
    }

    /**
     * @param PurchaseOrder[] $purchaseOrders
     * @return array{byCategory: array<string, array{net: float, gross: float}>, bySupplier: array<string, array{net: float, gross: float}>, byMonth: array<string, array{net: float, gross: float}>, total: array{net: float, gross: float}, count: int}
     */
    private function buildSpendReport(array $purchaseOrders): array
    {
        $report = ['byCategory' => [], 'bySupplier' => [], 'byMonth' => [], 'total' => ['net' => 0.0, 'gross' => 0.0], 'count' => count($purchaseOrders)];

        $add = static function (array &$bucket, string $key, float $net, float $gross): void {
            $bucket[$key] ??= ['net' => 0.0, 'gross' => 0.0];
            $bucket[$key]['net']   += $net;
            $bucket[$key]['gross'] += $gross;
        };

        foreach ($purchaseOrders as $po) {
            $supplier = $po->getSupplier()->getSupplierName();
            $month    = $po->getApprovedAt()?->format('Y-m') ?? '';

            $items = [];
            foreach ($po->getLines() as $line) {
                $items[] = [$line->getCategoryLabel(), (float) $line->getNetTotal(), (float) $line->getGrossTotal()];
            }
            if ((float) $po->getDeliveryCharge() > 0) {
                $items[] = ['Delivery', (float) $po->getDeliveryCharge(), (float) $po->getDeliveryCharge() + (float) $po->getDeliveryVat()];
            }

            foreach ($items as [$category, $net, $gross]) {
                $add($report['byCategory'], $category, $net, $gross);
                $add($report['bySupplier'], $supplier, $net, $gross);
                $add($report['byMonth'], $month, $net, $gross);
                $report['total']['net']   += $net;
                $report['total']['gross'] += $gross;
            }
        }

        ksort($report['byCategory']);
        ksort($report['bySupplier']);
        ksort($report['byMonth']);

        return $report;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: ?int} [from, to, supplier id] — defaults to the current calendar year to date. */
    private function parseSpendFilters(Request $request): array
    {
        $from = $this->parseDate($request->query->get('from', '')) ?? new \DateTimeImmutable('first day of january this year midnight');
        $to   = $this->parseDate($request->query->get('to', '')) ?? new \DateTimeImmutable('today');
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $supplierId = (int) $request->query->get('supplier', 0) ?: null;

        return [$from, $to, $supplierId];
    }

    /** @return array{tab: string, q: string, supplier: ?int, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable} */
    private function parseListFilters(Request $request): array
    {
        $tab = $request->query->get('tab', '');
        if (!array_key_exists($tab, PurchaseOrderRepository::TABS)) {
            // Admins land on whatever's waiting for them; everyone else on what's in progress.
            $tab = $this->isGranted('ROLE_ADMIN') && $this->purchaseOrderRepository->countAwaitingApproval() > 0 ? 'awaiting' : 'open';
        }

        return [
            'tab'      => $tab,
            'q'        => trim($request->query->get('q', '')),
            'supplier' => (int) $request->query->get('supplier', 0) ?: null,
            'from'     => $this->parseDate($request->query->get('from', '')),
            'to'       => $this->parseDate($request->query->get('to', '')),
        ];
    }

    /**
     * Header fields from a form. The supplier reference and expected date are only touched when the
     * form sends them, so a form without them (the draft's supplier/delivery box) leaves them alone.
     * With {allFields} false the supplier and delivery charge are never read. Returns an error message or null.
     */
    private function applyDetails(Request $request, PurchaseOrder $po, bool $allFields): ?string
    {
        $hasReference = $request->request->has('supplierReference');
        $hasExpected  = $request->request->has('expectedAt');
        $reference    = trim($request->request->get('supplierReference', ''));
        $expectedRaw  = trim($request->request->get('expectedAt', ''));
        $expectedAt   = $this->parseDate($expectedRaw);

        if (mb_strlen($reference) > 100) {
            return 'Keep the supplier\'s reference under 100 characters.';
        }
        if ($expectedRaw !== '' && $expectedAt === null) {
            return 'Enter a valid expected delivery date.';
        }

        if ($allFields) {
            [$delivery, $error] = $this->parseMoney($request->request->get('deliveryCharge', ''), 'delivery charge');
            $deliveryVat = $request->request->get('deliveryVatCode', Product::VAT_STANDARD);
            if ($error) {
                return $error;
            }
            if (!array_key_exists($deliveryVat, Product::VAT_LABELS)) {
                return 'Choose a VAT rate for delivery.';
            }

            $supplierId = (int) $request->request->get('supplierId', 0);
            if ($supplierId && $supplierId !== $po->getSupplier()->getId()) {
                $supplier = $this->userRepository->find($supplierId);
                if (!$supplier || !$supplier->isSupplier() || $supplier->isDeleted()) {
                    return 'Choose a supplier.';
                }
                $po->setSupplier($supplier);
            }

            $po->setDeliveryCharge($delivery ?? '0.00');
            $po->setDeliveryVatCode($deliveryVat);
        }

        if ($hasReference) {
            $po->setSupplierReference($reference !== '' ? $reference : null);
        }
        if ($hasExpected) {
            $po->setExpectedAt($expectedAt);
        }

        return null;
    }

    /** Line fields from a form. A stock line keeps its product and description; a non-stock line needs a description and category. Returns an error message or null. */
    private function applyLine(Request $request, PurchaseOrderLine $line, bool $isNew): ?string
    {
        $qtyRaw       = trim($request->request->get('qty', ''));
        $supplierCode = trim($request->request->get('supplierCode', ''));
        $vatCode      = $request->request->get('vatCode', $line->getVatCode());

        if (!ctype_digit($qtyRaw) || (int) $qtyRaw < 1 || (int) $qtyRaw > 100000) {
            return 'Enter a quantity of at least 1.';
        }
        if (!array_key_exists($vatCode, Product::VAT_LABELS)) {
            return 'Choose a VAT rate.';
        }
        if (mb_strlen($supplierCode) > 100) {
            return 'Keep the supplier\'s code under 100 characters.';
        }

        [$unitCost, $error] = $this->parseMoney($request->request->get('unitCost', ''), 'unit cost');
        if ($error) {
            return $error;
        }
        if ($unitCost === null && !($isNew && $line->isStockLine())) {
            return 'Enter the unit cost (net of VAT).';
        }

        if (!$line->isStockLine()) {
            $description = trim($request->request->get('description', ''));
            $category    = $request->request->get('category', '');
            if ($description === '' || mb_strlen($description) > 255) {
                return 'Describe what you\'re ordering (under 255 characters).';
            }
            if (!array_key_exists($category, PurchaseOrderLine::CATEGORY_LABELS)) {
                return 'Choose a category.';
            }
            $line->setDescription($description);
            $line->setCategory($category);
        }

        $line->setQty((int) $qtyRaw);
        if ($unitCost !== null) {
            $line->setUnitCost($unitCost);
        }
        $line->setVatCode($vatCode);
        $line->setSupplierCode($supplierCode !== '' ? $supplierCode : null);

        return null;
    }

    /** @return array{0: ?string, 1: ?string} [the amount as a 2dp string or null when blank, an error message or null] */
    private function parseMoney(string $raw, string $label): array
    {
        $raw = trim(str_replace(['£', ','], '', $raw));
        if ($raw === '') {
            return [null, null];
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw >= 1000000) {
            return [null, 'Enter a valid ' . $label . '.'];
        }

        return [number_format((float) $raw, 2, '.', ''), null];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $date && $date->format('Y-m-d') === $raw ? $date : null;
    }

    /** Runs a status change, turning the service's \LogicException into an error flash. {action} returns the success message. */
    private function attempt(PurchaseOrder $po, callable $action): Response
    {
        try {
            $this->addFlash('success', $action());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToShow($po);
    }

    private function assertCsrf(Request $request, string $tokenId): bool
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return false;
        }
        return true;
    }

    private function assertEditable(Request $request, PurchaseOrder $po): bool
    {
        if (!$this->assertCsrf($request, 'admin_po_lines_' . $po->getId())) {
            return false;
        }
        if (!$po->isEditable()) {
            $this->addFlash('error', 'Lines can only be changed while the order is a draft.');
            return false;
        }
        return true;
    }

    private function redirectToShow(PurchaseOrder $po): Response
    {
        return $this->redirectToRoute('app_admin_purchase_order_show', ['id' => $po->getId()]);
    }
}
