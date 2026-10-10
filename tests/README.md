# Tests

```bash
php bin/phpunit                                    # everything
php bin/phpunit tests/Functional/Controller/Web    # one area
php bin/phpunit --filter EventControllerTest       # one class
```

Tests run against the `_test` database (see `config/packages/doctrine.yaml`), with mail going to a
null transport. They never call Stripe.

## Layout

Tests mirror `src/Controller`:

```
tests/
  Functional/
    RouteSmokeTest.php          every parameterless route renders without a 500
    Controller/
      Admin/  Api/  Web/        one folder per src/Controller namespace
  Support/
    FunctionalTestCase.php      base class for controller tests
    CreatesTestEntities.php     factories: createUser(), createEvent(), createAttendee(), ...
    CreatesTestAdmin.php        the shared PHPUnit admin account
    CreatesTestCertification.php
```

Naming:

- `<Controller>Test` (e.g. `Web/EventControllerTest`): broad coverage of one controller. It checks
  that pages load, that access rules hold, and that the main action works.
- `<ControllerPrefix><Scenario>Test` (e.g. `Api/DoorExitSessionMatchingTest`): an in-depth test
  of one flow or regression. Add one of these when a flow needs more than a couple of tests.

Every controller should have at least one test. When you add a controller, add its `<Controller>Test`.

## Writing a controller test

Extend `App\Tests\Support\FunctionalTestCase`. It gives you a browser (`$this->client`) plus:

| Helper | Use |
|---|---|
| `loginAsAdmin()` / `loginAsTeam()` / `loginAsMember()` / `loginAs($user)` | authenticate without the login form |
| `createUser()`, `createEvent()`, `createAttendee()`, `createTag()`, `createNewsPost()`, `createProduct()`, `createPayment()` | minimal valid records, cleaned up automatically |
| `submitFormAt($pageUrl, $action, $values)` | load a page and submit its real form (CSRF token included) |
| `csrfTokenFor($pageUrl, $action)` / `jsConstant($name)` | get a CSRF token for a direct POST (form-based, or JS/fetch-based) |
| `assertPageLoads()`, `assertRequiresLogin()`, `assertForbidden()`, `followRedirectAndAssertSee()`, `assertSee()`, `json()` | common assertions |

Submit the rendered form instead of POSTing hand-built data wherever you can. That way the test
also checks that the template renders the form, its field names and its CSRF token correctly.

```php
class AdminTagControllerTest extends FunctionalTestCase
{
    public function testAdminCanCreateATag(): void
    {
        $this->loginAsAdmin();

        $name = 'PHPUnit tag ' . $this->uniqueSuffix();
        $this->submitFormAt('/admin/settings/tags/new', '/admin/settings/tags/new', ['name' => $name]);
        $this->followRedirectAndAssertSee('Tag created.');

        $tag = $this->track($this->em()->getRepository(Tag::class)->findOneBy(['name' => $name]));
        self::assertNotNull($tag);
    }
}
```

## Test data and cleanup

The test database is shared and is **not** reset between tests:

- Everything made with a `create*()` factory is removed in `tearDown()`, newest first.
- If the **application** creates something during your test (a registration, a tag from a form),
  look it up and pass it to `$this->track()` so it gets removed too.
- Notes attached to a tracked record, and reset-password/magic-link tokens for a tracked user, are
  removed automatically.
- Give records unique names with `uniqueSuffix()`, and assert only on records you created. Never
  assume a table is empty.
- If you need the same setup in two tests, add a factory to `CreatesTestEntities`.

Some older tests extend `WebTestCase` directly and clean up by hand. They work fine. Move them onto
`FunctionalTestCase` when you're next working in them.
