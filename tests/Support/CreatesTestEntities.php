<?php

namespace App\Tests\Support;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\NewsPost;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Tag;
use App\Entity\User;

/**
 * Factories for the records most tests need, each persisted, flushed and tracked for cleanup.
 * Defaults are the minimum valid record; pass named arguments for anything a test cares about,
 * and adjust the returned entity (then flush via $this->em()) for anything rarer.
 *
 * Add a factory here when a second test needs the same setup — keep one-off setup in the test.
 * Only usable from FunctionalTestCase (relies on its em() and track()).
 */
trait CreatesTestEntities
{
    /** @param list<string> $roles */
    protected function createUser(
        array $roles = [User::ROLE_MEMBER],
        ?string $email = null,
        string $firstName = 'Test',
        ?string $lastName = null,
        ?string $plainPassword = null,
    ): User {
        $suffix = $this->uniqueSuffix();

        $user = new User();
        $user->setEmail($email ?? sprintf('phpunit-%s@example.test', $suffix));
        $user->setFirstName($firstName);
        $user->setLastName($lastName ?? 'User' . $suffix);
        $user->setRoles($roles);
        $user->setPassword($plainPassword !== null
            ? $this->service('security.user_password_hasher')->hashPassword($user, $plainPassword)
            : 'unused — tests log in via loginUser(), which skips password checking');

        return $this->persistAndTrack($user);
    }

    /** A one-off, published, open-booking (no access restriction) event tomorrow evening. */
    protected function createEvent(
        string $status = Event::STATUS_PUBLISHED,
        ?\DateTimeImmutable $date = null,
        ?int $maxAttendees = null,
        ?string $title = null,
    ): Event {
        $event = new Event();
        $event->setTitle($title ?? 'PHPUnit event ' . $this->uniqueSuffix());
        $event->setDate($date ?? new \DateTimeImmutable('tomorrow'));
        $event->setTimeFrom('18:00');
        $event->setTimeTo('20:00');
        $event->setLocation('Test wall');
        $event->setStatus($status);
        $event->setMaxAttendees($maxAttendees);

        return $this->persistAndTrack($event);
    }

    protected function createAttendee(Event $event, User $user, string $status = Attendee::STATUS_CONFIRMED): Attendee
    {
        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setStatus($status);

        return $this->persistAndTrack($attendee);
    }

    protected function createTag(bool $public = false): Tag
    {
        $tag = new Tag();
        $tag->setName('PHPUnit tag ' . $this->uniqueSuffix());
        $tag->setPublic($public);

        return $this->persistAndTrack($tag);
    }

    protected function createNewsPost(bool $published = true, ?User $author = null): NewsPost
    {
        $suffix = $this->uniqueSuffix();

        $post = new NewsPost();
        $post->setTitle('PHPUnit news ' . $suffix);
        $post->setSlug('phpunit-news-' . $suffix);
        $post->setBody('<p>PHPUnit news body ' . $suffix . '</p>');
        $post->setAuthor($author);
        $post->setPublishedAt($published ? new \DateTimeImmutable('-1 hour') : null);

        return $this->persistAndTrack($post);
    }

    protected function createProduct(string $type = Product::TYPE_SERVICE, string $price = '5.00'): Product
    {
        $product = new Product();
        $product->setName('PHPUnit product ' . $this->uniqueSuffix());
        $product->setPrice($price);
        $product->setProductType($type);

        return $this->persistAndTrack($product);
    }

    /** A pending online payment with no Stripe PaymentIntent — so nothing ever calls out to Stripe for it. */
    protected function createPayment(User $user, string $amount = '10.00', string $method = Payment::METHOD_ONLINE): Payment
    {
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setAmount($amount);
        $payment->setMethod($method);

        return $this->persistAndTrack($payment);
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function persistAndTrack(object $entity): object
    {
        $this->em()->persist($entity);
        $this->em()->flush();

        return $this->track($entity);
    }
}
