<?php

namespace App\Tests\Support;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\MagicLink;
use App\Entity\Note;
use App\Entity\Product;
use App\Entity\ResetPasswordRequest;
use App\Entity\SalesOrder;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base class for controller tests — one browser per test, login helpers, form-driving helpers that
 * submit the real rendered forms (so the CSRF token, field names and action URL are all exercised
 * exactly as a browser would), and automatic cleanup of anything the test created.
 *
 * Cleanup: every entity made through CreatesTestEntities is tracked and removed in tearDown(),
 * newest first. Anything the *application* creates during a test (a user from a registration form,
 * a tag from the admin form...) should be passed to track() once the test has looked it up, so it
 * gets removed too. Notes attached to any tracked record are removed automatically — most actions
 * add one, and Note has no foreign key that would cascade.
 *
 * The test database is shared and not reset between tests, so: never assume a table is empty, give
 * anything you create a unique name (see uniqueSuffix()), and assert on your own records only.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    use CreatesTestAdmin;
    use CreatesTestEntities;

    protected KernelBrowser $client;

    /** @var list<array{0: class-string, 1: int}> */
    private array $tracked = [];

    private const NOTEABLE_TYPES = [
        User::class       => Note::TYPE_MEMBER,
        Attendee::class   => Note::TYPE_ATTENDEE,
        Event::class      => Note::TYPE_EVENT,
        Product::class    => Note::TYPE_PRODUCT,
        SalesOrder::class => Note::TYPE_ORDER,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        try {
            $this->removeTracked();
        } finally {
            // Always shut the kernel down, even if cleanup failed — otherwise the next test fails
            // with "Booting the kernel before calling createClient()" and hides the real error.
            parent::tearDown();
        }
    }

    private function removeTracked(): void
    {
        $em = $this->em();
        $em->clear();

        foreach (array_reverse($this->tracked) as [$class, $id]) {
            if (isset(self::NOTEABLE_TYPES[$class])) {
                $em->createQuery('DELETE FROM ' . Note::class . ' n WHERE n.noteableType = :type AND n.noteableId = :id')
                    ->execute(['type' => self::NOTEABLE_TYPES[$class], 'id' => $id]);
            }

            if ($class === User::class) {
                // Login/reset tokens the app may have issued during the test — never interesting to
                // a test, but their foreign key would otherwise block removing the user.
                foreach ([ResetPasswordRequest::class, MagicLink::class] as $tokenClass) {
                    $em->createQuery('DELETE FROM ' . $tokenClass . ' t WHERE IDENTITY(t.user) = :id')->execute(['id' => $id]);
                }
            }

            // Re-fetched by id rather than holding the original object: the browser reboots the
            // kernel between requests, so an entity from before a request belongs to a stale
            // EntityManager — and the test itself may already have deleted it.
            $entity = $em->find($class, $id);
            if ($entity !== null) {
                $em->remove($entity);
                $em->flush();
            }
        }

        $this->tracked = [];
    }

    // ---------------------------------------------------------------- services

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        return static::getContainer()->get($id);
    }

    /**
     * Registers {entity} (already flushed, so it has an id) for removal in tearDown().
     *
     * @template T of object
     * @param T $entity
     * @return T
     */
    protected function track(object $entity): object
    {
        $this->tracked[] = [$this->em()->getClassMetadata($entity::class)->getName(), $entity->getId()];

        return $entity;
    }

    /** A short random string for unique names/emails, so tests never collide with each other or with existing data. */
    protected function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }

    // ---------------------------------------------------------------- authentication

    /** The shared PHPUnit admin (see CreatesTestAdmin) — kept between runs rather than tracked, since admin actions in other tests reference it. */
    protected function loginAsAdmin(): User
    {
        $admin = $this->findOrCreateAdmin();
        $this->client->loginUser($admin);

        return $admin;
    }

    protected function loginAsTeam(): User
    {
        return $this->loginAs($this->createUser(roles: [User::ROLE_TEAM]));
    }

    protected function loginAsMember(): User
    {
        return $this->loginAs($this->createUser());
    }

    protected function loginAs(User $user): User
    {
        $this->client->loginUser($user);

        return $user;
    }

    // ---------------------------------------------------------------- requests

    protected function get(string $url): Crawler
    {
        return $this->client->request('GET', $url);
    }

    /**
     * Loads {pageUrl}, then submits the form on it that posts to {action}, with {values} overriding
     * the rendered defaults. Returns the response's crawler — not following any redirect.
     *
     * @param array<string, mixed> $values
     */
    protected function submitFormAt(string $pageUrl, string $action, array $values = []): Crawler
    {
        $form = $this->findForm($this->get($pageUrl), $pageUrl, $action);

        return $this->client->submit($form->form(), $values);
    }

    /** The CSRF token rendered into the form on {pageUrl} that posts to {action} — for POSTing to an endpoint directly (e.g. JSON, or a button that isn't a plain form). */
    protected function csrfTokenFor(string $pageUrl, string $action): string
    {
        $form = $this->findForm($this->get($pageUrl), $pageUrl, $action);

        return (string) $form->filter('input[name="_csrf_token"]')->attr('value');
    }

    /** A string constant the current page hands to its inline JS, e.g. `const csrfToken = "…";` — for endpoints only ever called via fetch(). */
    protected function jsConstant(string $name): string
    {
        $found = preg_match('/const ' . preg_quote($name, '/') . '\s*=\s*("(?:[^"\\\\]|\\\\.)*");/', $this->client->getResponse()->getContent(), $m);
        self::assertSame(1, $found, sprintf('No JS constant "%s" on the current page.', $name));

        return json_decode($m[1], flags: JSON_THROW_ON_ERROR);
    }

    private function findForm(Crawler $crawler, string $pageUrl, string $action): Crawler
    {
        self::assertResponseIsSuccessful(sprintf('Expected %s to load before submitting its form.', $pageUrl));

        $form = $crawler->filter(sprintf('form[action="%s"]', $action));
        self::assertGreaterThan(0, $form->count(), sprintf('No form posting to %s on %s.', $action, $pageUrl));

        return $form->first();
    }

    // ---------------------------------------------------------------- assertions

    /** GETs {url} and asserts a 200 — the basic "this page renders" check. */
    protected function assertPageLoads(string $url): Crawler
    {
        $crawler = $this->get($url);
        self::assertResponseIsSuccessful(sprintf('Expected %s to load.', $url));

        return $crawler;
    }

    /** Asserts an anonymous visitor is bounced to the login page. Call before logging in. */
    protected function assertRequiresLogin(string $url, string $method = 'GET'): void
    {
        $this->client->request($method, $url);
        self::assertResponseRedirects('/login', null, sprintf('Expected %s %s to require login.', $method, $url));
    }

    /** Asserts the currently logged-in user is refused {url} — App\Security\AccessDeniedHandler sends them home with an "Access denied." flash rather than a bare 403. */
    protected function assertForbidden(string $url, string $method = 'GET'): void
    {
        $this->client->request($method, $url);
        self::assertResponseRedirects('/', null, sprintf('Expected %s %s to be forbidden.', $method, $url));
        $this->followRedirectAndAssertSee('Access denied.');
    }

    /** Follows the pending redirect and asserts the page it lands on shows {text} (typically a flash message). */
    protected function followRedirectAndAssertSee(string $text): Crawler
    {
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->assertSee($text);

        return $crawler;
    }

    /** Asserts the current response body contains {text}, HTML-escaped as Twig would render it. */
    protected function assertSee(string $text): void
    {
        self::assertStringContainsString(
            htmlspecialchars($text, ENT_QUOTES),
            $this->client->getResponse()->getContent(),
        );
    }

    /** @return array<mixed> the current response decoded as JSON */
    protected function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
