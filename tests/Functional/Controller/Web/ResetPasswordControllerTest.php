<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

/** "Forgot password". The mailer is a null transport in the test environment, so nothing is actually sent. */
class ResetPasswordControllerTest extends FunctionalTestCase
{
    public function testRequestingAResetAlwaysLandsOnCheckEmail(): void
    {
        $user = $this->createUser();

        // Same response whether or not the address exists, so the form can't be used to probe for accounts.
        foreach ([$user->getEmail(), sprintf('phpunit-nobody-%s@example.test', $this->uniqueSuffix())] as $email) {
            $this->submitFormAt('/reset-password', '/reset-password', ['email' => $email]);
            self::assertResponseRedirects('/reset-password/check-email');
            $this->client->followRedirect();
            self::assertResponseIsSuccessful();
        }
    }

    public function testInvalidResetLinkIsRejected(): void
    {
        $this->get('/reset-password/reset/' . str_repeat('x', 40));

        self::assertResponseRedirects('/reset-password');
    }
}
