<?php

namespace App\Tests\Functional;

use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\SalesOrder;
use App\Entity\User;
use App\Tests\Support\CreatesTestAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the sale-with-payments-delete workflow: a full cash sale from start to finish (new sale
 * -> add an item -> complete with cash -> payment/status show correctly on the order), and the
 * delete guard added after a production bug where deleting a draft order could silently orphan a
 * Payment row still attached to it (Payment.order is nullable with onDelete: SET NULL, so the
 * database never rejects the delete outright — it just leaves the payment behind with no order,
 * which then reads as a "Donation" in the UI). See AdminSalesController::delete().
 *
 * The rule under test: a draft order can be deleted — payments and all — only when every payment
 * on it is still pending or failed. Any other status (succeeded, refunded, partially refunded)
 * must block deletion, since that's real financial history worth keeping as a record.
 *
 * Every order here uses a "service" product — its fulfilment handler (ServiceFulfilmentHandler)
 * is a deliberate no-op, so completing an order never has side effects beyond the sale itself,
 * keeping this test focused purely on the payment/order lifecycle.
 */
class AdminSaleDeleteWorkflowTest extends WebTestCase
{
    use CreatesTestAdmin;

    /** @var int[] */
    private array $orderIds = [];
    /** @var int[] */
    private array $productIds = [];
    /** @var int[] */
    private array $memberIds = [];

    public function testFullCashSaleProcessCompletesAndRecordsThePayment(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());
        $em = static::getContainer()->get('doctrine')->getManager();

        $product = $this->createServiceProduct($em, '9.50');
        [$order, ] = $this->createDraftOrderWithServiceItem($client, $em, $product);

        // Complete with cash via the real "Cash" button on the order page.
        $crawler = $client->getCrawler();
        $client->submit($crawler->selectButton('Cash')->form());
        self::assertResponseRedirects('/admin/sales/' . $order->getId());
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Order completed', $content, 'Expected the cash-completion success flash.');

        $em->clear();
        $reloaded = $em->getRepository(SalesOrder::class)->find($order->getId());
        self::assertNotNull($reloaded);
        self::assertSame(SalesOrder::STATUS_COMPLETE, $reloaded->getStatus(), 'Completing with cash should mark the order complete.');

        $payments = $reloaded->getPayments();
        self::assertCount(1, $payments, 'Expected exactly one payment recorded against the order.');
        $payment = $payments->first();
        self::assertSame(Payment::METHOD_CASH, $payment->getMethod());
        self::assertSame(Payment::STATUS_SUCCEEDED, $payment->getStatus(), 'A cash payment is recorded as succeeded immediately.');
        self::assertSame('9.50', $payment->getAmount());

        // The payment list and status shown on the order page itself.
        self::assertStringContainsString('£9.50', $content);
        self::assertStringContainsString('Cash', $content);
        self::assertStringContainsString('Succeeded', $content);
    }

    public function testDeletingADraftOrderWithOnlyPendingOrFailedPaymentsDeletesThemToo(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());
        $em = static::getContainer()->get('doctrine')->getManager();

        $product = $this->createServiceProduct($em, '4.00');
        [$order, ] = $this->createDraftOrderWithServiceItem($client, $em, $product);
        $orderId = $order->getId();

        $pending = $this->attachPayment($em, $order, succeededAt: null, failedAt: null);
        $failed  = $this->attachPayment($em, $order, succeededAt: null, failedAt: new \DateTimeImmutable());
        $pendingId = $pending->getId();
        $failedId  = $failed->getId();

        // Force the order's payments collection to be reloaded from the database — it was
        // initialised empty as a plain collection when the order was first constructed via the
        // "new sale" request, and never automatically syncs just because Payment::setOrder() was
        // called from the owning side afterwards.
        $em->refresh($order);

        $crawler = $client->request('GET', '/admin/sales/' . $orderId);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Delete order')->form());

        self::assertResponseRedirects('/admin/sales');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Order deleted', $client->getResponse()->getContent());

        $em->clear();
        self::assertNull($em->getRepository(SalesOrder::class)->find($orderId), 'The order should have been deleted.');
        self::assertNull($em->getRepository(Payment::class)->find($pendingId), 'The pending payment should have been deleted along with the order.');
        self::assertNull($em->getRepository(Payment::class)->find($failedId), 'The failed payment should have been deleted along with the order.');

        // Already gone — nothing left for tearDown to remove for this order.
        $this->orderIds = array_diff($this->orderIds, [$orderId]);
    }

    public function testDeletingADraftOrderWithASucceededPaymentIsBlocked(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());
        $em = static::getContainer()->get('doctrine')->getManager();

        $product = $this->createServiceProduct($em, '4.00');
        [$order, ] = $this->createDraftOrderWithServiceItem($client, $em, $product);
        $orderId = $order->getId();

        $succeeded = $this->attachPayment($em, $order, succeededAt: new \DateTimeImmutable(), failedAt: null);
        $succeededId = $succeeded->getId();

        $em->refresh($order);

        $crawler = $client->request('GET', '/admin/sales/' . $orderId);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Delete order')->form());

        self::assertResponseRedirects('/admin/sales/' . $orderId);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // Twig HTML-escapes the flash message's apostrophes (' -> &#39;) — match around them.
        self::assertStringContainsString('has payment history', $client->getResponse()->getContent());
        self::assertStringContainsString('kept as a record', $client->getResponse()->getContent());

        $em->clear();
        self::assertNotNull($em->getRepository(SalesOrder::class)->find($orderId), 'The order must not have been deleted.');
        self::assertNotNull($em->getRepository(Payment::class)->find($succeededId), 'The succeeded payment must not have been deleted.');
    }

    /** @return array{0: SalesOrder, 1: User} */
    private function createDraftOrderWithServiceItem(KernelBrowser $client, EntityManagerInterface $em, Product $product): array
    {
        $member = $this->createMember($em);

        $crawler = $client->request('GET', '/admin/users/' . $member->getId());
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('+ New sale')->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $orderId = $this->extractOrderIdFromPath($client->getRequest()->getPathInfo());
        self::assertNotNull($orderId, 'Expected to land on the new order\'s show page.');
        $this->orderIds[] = $orderId;

        $crawler = $client->getCrawler();
        $client->submit($crawler->selectButton($product->getName())->form());
        self::assertResponseRedirects('/admin/sales/' . $orderId);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($product->getName(), $client->getResponse()->getContent());

        $order = $em->getRepository(SalesOrder::class)->find($orderId);
        self::assertNotNull($order);

        return [$order, $member];
    }

    private function attachPayment(EntityManagerInterface $em, SalesOrder $order, ?\DateTimeImmutable $succeededAt, ?\DateTimeImmutable $failedAt): Payment
    {
        $payment = new Payment();
        $payment->setUser($order->getUser());
        $payment->setOrder($order);
        $payment->setAmount($order->getTotal());
        $payment->setMethod(Payment::METHOD_TERMINAL);
        // Reuse $order's own createdBy rather than calling findOrCreateAdmin() again here — that
        // would fetch/create the admin via the container's *current* doctrine manager, which isn't
        // guaranteed to be the same EntityManager instance as $em by this point in the test.
        $payment->setTakenBy($order->getCreatedBy());
        if ($succeededAt !== null) {
            $payment->setSucceededAt($succeededAt);
        }
        if ($failedAt !== null) {
            $payment->setFailedAt($failedAt);
            $payment->setFailureReason('TEMP test failure.');
        }
        $em->persist($payment);
        $em->flush();

        return $payment;
    }

    private function createMember(EntityManagerInterface $em): User
    {
        $member = new User();
        $member->setEmail(sprintf('phpunit-sale-member-%s@example.test', bin2hex(random_bytes(4))));
        $member->setPassword('unused — no login needed for this member');
        $member->setRoles([User::ROLE_MEMBER]);
        $member->setFirstName('TEMP');
        $member->setLastName('Sale Test Member');

        $em->persist($member);
        $em->flush();

        $this->memberIds[] = $member->getId();

        return $member;
    }

    private function createServiceProduct(EntityManagerInterface $em, string $price): Product
    {
        $product = new Product();
        $product->setName('TEMP service product ' . bin2hex(random_bytes(4)));
        $product->setPrice($price);
        $product->setProductType(Product::TYPE_SERVICE);

        $em->persist($product);
        $em->flush();

        $this->productIds[] = $product->getId();

        return $product;
    }

    private function extractOrderIdFromPath(string $pathInfo): ?int
    {
        return preg_match('#^/admin/sales/(\d+)$#', $pathInfo, $matches) ? (int) $matches[1] : null;
    }

    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        foreach ($this->orderIds as $orderId) {
            $order = $em->getRepository(SalesOrder::class)->find($orderId);
            if ($order) {
                foreach ($order->getPayments() as $payment) {
                    $em->remove($payment);
                }
                foreach ($order->getRows() as $row) {
                    $em->remove($row);
                }
                $em->remove($order);
            }
        }
        $em->flush();

        foreach ($this->productIds as $productId) {
            $product = $em->getRepository(Product::class)->find($productId);
            if ($product) {
                $em->remove($product);
            }
        }

        foreach ($this->memberIds as $memberId) {
            $member = $em->getRepository(User::class)->find($memberId);
            if ($member) {
                $em->remove($member);
            }
        }

        $em->flush();

        parent::tearDown();
    }
}
