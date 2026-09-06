<?php

namespace App\Controller;

use App\Entity\CreditProduct;
use App\Entity\Event;
use App\Entity\EventTicketProduct;
use App\Entity\InventoryMovement;
use App\Entity\MembershipProduct;
use App\Entity\MembershipType;
use App\Entity\Product;
use App\Entity\StockProduct;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\MembershipTypeRepository;
use App\Repository\ProductRepository;
use App\Repository\StockProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manage products — the sellable items used to add a membership to a person, sell stock/services,
 * grant event access credits, or sell an event ticket. Common fields live on Product; type-specific
 * fields live on a 1:1 extension entity (StockProduct, CreditProduct, MembershipProduct,
 * EventTicketProduct) keyed on the product id. Service products need no extension.
 */
#[Route('/admin/settings/products')]
#[IsGranted('ROLE_ADMIN')]
class AdminProductController extends AbstractController
{
    private const TYPES = [
        Product::TYPE_STOCK,
        Product::TYPE_SERVICE,
        Product::TYPE_CREDIT,
        Product::TYPE_MEMBERSHIP,
        Product::TYPE_EVENT_TICKET,
    ];

    private const VAT_CODES = [
        Product::VAT_STANDARD,
        Product::VAT_REDUCED,
        Product::VAT_ZERO,
        Product::VAT_EXEMPT,
    ];

    #[Route('', name: 'app_admin_settings_products')]
    public function index(Request $request, ProductRepository $productRepository): Response
    {
        $query    = trim($request->query->get('q', ''));
        $products = $productRepository->search($query);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/settings/products/_list.html.twig', [
                'products'     => $products,
                'currentQuery' => $query,
            ]);
        }

        return $this->render('admin/settings/products/index.html.twig', [
            'products'     => $products,
            'currentQuery' => $query,
        ]);
    }

    #[Route('/new', name: 'app_admin_settings_product_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ProductRepository $productRepository,
        StockProductRepository $stockProductRepository,
        MembershipTypeRepository $membershipTypeRepository,
        EventRepository $eventRepository,
        EntityManagerInterface $em,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_product_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            [$error, $product] = $this->buildProduct(
                $request,
                new Product(),
                $productRepository,
                $stockProductRepository,
                $membershipTypeRepository,
                $eventRepository,
                $em,
            );

            if (!$error) {
                $em->persist($product);
                $em->flush();

                $this->addFlash('success', 'Product created.');
                return $this->redirectToRoute('app_admin_settings_products');
            }
        }

        return $this->render('admin/settings/products/new.html.twig', [
            'error'           => $error,
            'types'           => self::TYPES,
            'vatCodes'        => self::VAT_CODES,
            'membershipTypes' => $membershipTypeRepository->findBy([], ['name' => 'ASC']),
            'events'          => $eventRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_settings_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Product $product,
        ProductRepository $productRepository,
        StockProductRepository $stockProductRepository,
        MembershipTypeRepository $membershipTypeRepository,
        EventRepository $eventRepository,
        EntityManagerInterface $em,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_product_edit_' . $product->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            [$error] = $this->buildProduct(
                $request,
                $product,
                $productRepository,
                $stockProductRepository,
                $membershipTypeRepository,
                $eventRepository,
                $em,
                lockType: true,
            );

            if (!$error) {
                $em->flush();

                $this->addFlash('success', 'Product updated.');
                return $this->redirectToRoute('app_admin_settings_product_edit', ['id' => $product->getId()]);
            }
        }

        return $this->render('admin/settings/products/edit.html.twig', [
            'product'         => $product,
            'error'           => $error,
            'vatCodes'        => self::VAT_CODES,
            'membershipTypes' => $membershipTypeRepository->findBy([], ['name' => 'ASC']),
            'events'          => $eventRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_settings_product_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_delete_' . $product->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $em->remove($product);
        $em->flush();

        $this->addFlash('success', 'Product deleted.');
        return $this->redirectToRoute('app_admin_settings_products');
    }

    /** Manual stock adjustment (restock, damage, stocktake correction, etc.) from the pencil icon on the products list. */
    #[Route('/inventory', name: 'app_admin_settings_product_inventory', methods: ['POST'])]
    public function inventory(Request $request, ProductRepository $productRepository, EntityManagerInterface $em): Response
    {
        $product = $productRepository->find((int) $request->request->get('productId', 0));
        if (!$product instanceof Product) {
            $this->addFlash('error', 'Product not found.');
            return $this->redirectToRoute('app_admin_settings_products');
        }

        if (!$this->isCsrfTokenValid('admin_product_inventory_' . $product->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_admin_settings_products');
        }

        $stockProduct = $product->getStockProduct();
        if ($product->getProductType() !== Product::TYPE_STOCK || !$stockProduct) {
            $this->addFlash('error', 'Not a stock product.');
            return $this->redirectToRoute('app_admin_settings_products');
        }

        $direction = $request->request->get('direction', '');
        $qtyRaw    = $request->request->get('qty', '');
        $note      = trim($request->request->get('note', ''));

        if (!in_array($direction, ['add', 'remove'], true)) {
            $this->addFlash('error', 'Choose add or remove stock.');
            return $this->redirectToRoute('app_admin_settings_products');
        }
        if (!ctype_digit((string) $qtyRaw) || (int) $qtyRaw < 1) {
            $this->addFlash('error', 'Enter a valid quantity.');
            return $this->redirectToRoute('app_admin_settings_products');
        }
        if ($note === '') {
            $this->addFlash('error', 'A note is required.');
            return $this->redirectToRoute('app_admin_settings_products');
        }

        /** @var User $admin */
        $admin = $this->getUser();

        $movement = new InventoryMovement();
        $movement->setStockProduct($stockProduct);
        $movement->setQuantityChange($direction === 'add' ? (int) $qtyRaw : -(int) $qtyRaw);
        $movement->setNetPrice($stockProduct->getCostPrice());
        $movement->setReason(InventoryMovement::REASON_ADJUSTMENT);
        $movement->setNote($note);
        $movement->setCreatedBy($admin);

        $em->persist($movement);
        $em->flush();

        $this->addFlash('success', 'Stock updated.');
        return $this->redirectToRoute('app_admin_settings_products');
    }

    /**
     * Populate $product's common fields, plus its type-specific extension entity, from the request.
     * Returns [errorMessage-or-null, $product]. On edit, the product type is locked and only its
     * existing extension entity's fields are updated.
     */
    private function buildProduct(
        Request $request,
        Product $product,
        ProductRepository $productRepository,
        StockProductRepository $stockProductRepository,
        MembershipTypeRepository $membershipTypeRepository,
        EventRepository $eventRepository,
        EntityManagerInterface $em,
        bool $lockType = false,
    ): array {
        $name  = trim($request->request->get('name', ''));
        $price = trim($request->request->get('price', ''));
        $vatCode = $request->request->get('vatCode', '');
        $productType = $lockType ? $product->getProductType() : $request->request->get('productType', '');

        if ($name === '') {
            return ['Name is required.', $product];
        }
        if (!is_numeric($price) || (float) $price < 0) {
            return ['Enter a valid price.', $product];
        }
        if (!in_array($vatCode, self::VAT_CODES, true)) {
            return ['Choose a valid VAT code.', $product];
        }
        if (!in_array($productType, self::TYPES, true)) {
            return ['Choose a valid product type.', $product];
        }

        // Products sharing a name form a variant group. Renaming one renames the whole group;
        // a new/unmatched name just starts (or joins) a group of its own.
        $oldName = $product->getId() ? $product->getName() : null;

        $product->setName($name);
        $product->setShortDescription(trim($request->request->get('shortDescription', '')) ?: null);
        $product->setVariantName(trim($request->request->get('variantName', '')) ?: null);
        $product->setVariantValue(trim($request->request->get('variantValue', '')) ?: null);
        $product->setPrice(number_format((float) $price, 2, '.', ''));
        $product->setVatCode($vatCode);
        $product->setProductType($productType);
        $product->setIsActive($request->request->has('isActive'));

        if ($oldName !== null && $oldName !== $name) {
            foreach ($productRepository->findBy(['name' => $oldName]) as $sibling) {
                if ($sibling !== $product) {
                    $sibling->setName($name);
                }
            }
        }

        return match ($productType) {
            Product::TYPE_STOCK => $this->applyStock($request, $product, $stockProductRepository, $em),
            Product::TYPE_CREDIT => $this->applyCredit($request, $product, $em),
            Product::TYPE_MEMBERSHIP => $this->applyMembership($request, $product, $membershipTypeRepository, $em),
            Product::TYPE_EVENT_TICKET => $this->applyEventTicket($request, $product, $eventRepository, $em),
            default => [null, $product],
        };
    }

    private function applyStock(Request $request, Product $product, StockProductRepository $stockProductRepository, EntityManagerInterface $em): array
    {
        $costPrice = trim($request->request->get('costPrice', ''));
        $sku       = trim($request->request->get('sku', ''));

        if ($sku === '') {
            return ['SKU is required for a stock product.', $product];
        }
        if (!is_numeric($costPrice) || (float) $costPrice < 0) {
            return ['Enter a valid cost price.', $product];
        }

        $duplicate = $stockProductRepository->findOneBy(['sku' => $sku]);
        $existing  = $product->getStockProduct();
        if ($duplicate && $duplicate !== $existing) {
            return ['A stock product with that SKU already exists.', $product];
        }

        $stockProduct = $existing ?? new StockProduct($product);
        $stockProduct->setSku($sku);
        $stockProduct->setCostPrice(number_format((float) $costPrice, 2, '.', ''));
        $em->persist($stockProduct);

        return [null, $product];
    }

    private function applyCredit(Request $request, Product $product, EntityManagerInterface $em): array
    {
        $creditsGranted = $request->request->get('creditsGranted', '');

        if (!ctype_digit((string) $creditsGranted) || (int) $creditsGranted < 1) {
            return ['Enter how many credits this product grants.', $product];
        }

        $creditProduct = $product->getCreditProduct() ?? new CreditProduct($product);
        $creditProduct->setCreditsGranted((int) $creditsGranted);
        $em->persist($creditProduct);

        return [null, $product];
    }

    private function applyMembership(Request $request, Product $product, MembershipTypeRepository $membershipTypeRepository, EntityManagerInterface $em): array
    {
        $membershipTypeId = (int) $request->request->get('membershipTypeId', 0);
        $membershipType   = $membershipTypeId ? $membershipTypeRepository->find($membershipTypeId) : null;

        if (!$membershipType instanceof MembershipType) {
            return ['Choose the membership type this product sells.', $product];
        }

        $membershipProduct = $product->getMembershipProduct() ?? new MembershipProduct($product);
        $membershipProduct->setMembershipType($membershipType);
        $em->persist($membershipProduct);

        return [null, $product];
    }

    private function applyEventTicket(Request $request, Product $product, EventRepository $eventRepository, EntityManagerInterface $em): array
    {
        $eventId = (int) $request->request->get('eventId', 0);
        $event   = $eventId ? $eventRepository->find($eventId) : null;

        if (!$event instanceof Event) {
            return ['Choose the event this product sells a ticket for.', $product];
        }

        $eventTicketProduct = $product->getEventTicketProduct() ?? new EventTicketProduct($product);
        $eventTicketProduct->setEvent($event);
        $em->persist($eventTicketProduct);

        return [null, $product];
    }
}
