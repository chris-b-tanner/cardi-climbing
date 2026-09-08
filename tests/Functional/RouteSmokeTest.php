<?php

namespace App\Tests\Functional;

use App\Tests\Support\CreatesTestAdmin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Hits every route that resolves to a real URL with no parameters at all (no entity id, token,
 * secret, etc.) and asserts it doesn't blow up with a server error. Logged in as an admin
 * throughout, so admin-only pages render for real instead of just redirecting to the login page.
 *
 * Deliberately loose: a redirect (e.g. a page that needs a query param the URL doesn't supply) is
 * a pass here — this is a smoke test for crashes, not a check of what each page actually shows.
 */
class RouteSmokeTest extends WebTestCase
{
    use CreatesTestAdmin;

    /** Routes that resolve with no params but aren't safe/meaningful to GET in this loop. */
    private const EXCLUDED_ROUTES = [
        'app_logout', // would end the authenticated session mid-loop
    ];

    public function testEveryParameterlessRouteLoadsWithoutServerError(): void
    {
        $client = static::createClient();
        $client->loginUser($this->findOrCreateAdmin());

        $router = static::getContainer()->get(RouterInterface::class);

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (in_array($name, self::EXCLUDED_ROUTES, true)) {
                continue;
            }

            $methods = $route->getMethods();
            if ($methods && !in_array('GET', $methods, true)) {
                continue; // POST-only action, not a page to load
            }

            try {
                $url = $router->generate($name);
            } catch (MissingMandatoryParametersException) {
                continue; // needs an id/token/secret/etc — out of scope for this smoke test
            }

            $client->request('GET', $url);

            self::assertLessThan(
                500,
                $client->getResponse()->getStatusCode(),
                sprintf('Route "%s" (%s) returned a server error (%d).', $name, $url, $client->getResponse()->getStatusCode()),
            );
        }
    }
}
