<?php

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\EventTicketProduct;
use App\Entity\Product;
use App\Tests\Support\CreatesTestAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression test for a production bug: deleting an event with a linked ticket product raised a
 * raw ForeignKeyConstraintViolationException (event_ticket_product.event_id is ON DELETE RESTRICT)
 * instead of a helpful message. See AdminEventController::delete().
 */
class AdminEventDeleteGuardTest extends WebTestCase
{
    use CreatesTestAdmin;

    private ?int $eventId = null;
    private ?int $productId = null;

    public function testDeletingAnEventWithALinkedTicketProductIsBlockedWithAFriendlyError(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());

        $em = static::getContainer()->get('doctrine')->getManager();

        $event = new Event();
        $event->setTitle('TEMP delete-guard test event');
        $event->setDate(new \DateTimeImmutable('+1 day'));
        $event->setTimeFrom('18:00');
        $event->setTimeTo('19:00');
        $event->setLocation('Test');
        $event->setStatus(Event::STATUS_PUBLISHED);
        $em->persist($event);

        $product = new Product();
        $product->setName('TEMP delete-guard test ticket');
        $product->setPrice('1.00');
        $product->setProductType(Product::TYPE_EVENT_TICKET);
        $em->persist($product);

        $ticketProduct = new EventTicketProduct($product);
        $ticketProduct->setEvent($event);
        $em->persist($ticketProduct);

        $em->flush();
        $this->eventId   = $event->getId();
        $this->productId = $product->getId();

        $crawler = $client->request('GET', '/admin/events/' . $event->getId() . '/edit');
        $form    = $crawler->selectButton('Delete event')->form();

        $client->submit($form);

        self::assertResponseRedirects('/admin/events/' . $event->getId());
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Delete the linked ticket product first', $client->getResponse()->getContent());
        self::assertStringContainsString('TEMP delete-guard test ticket', $client->getResponse()->getContent());

        $em->clear();
        self::assertNotNull($em->getRepository(Event::class)->find($this->eventId), 'The event must not have been deleted.');
    }

    protected function tearDown(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        if ($this->eventId !== null) {
            $event = $em->getRepository(Event::class)->find($this->eventId);
            if ($event) {
                foreach ($em->getRepository(EventTicketProduct::class)->findBy(['event' => $event]) as $etp) {
                    $em->remove($etp);
                }
                $em->flush();
                $em->remove($event);
            }
        }

        if ($this->productId !== null) {
            $product = $em->getRepository(Product::class)->find($this->productId);
            if ($product) {
                $em->remove($product);
            }
        }

        $em->flush();

        parent::tearDown();
    }
}
