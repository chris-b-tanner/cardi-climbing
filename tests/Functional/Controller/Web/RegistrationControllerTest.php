<?php

namespace App\Tests\Functional\Controller\Web;

use App\Repository\UserRepository;
use App\Tests\Support\FunctionalTestCase;

/** Self-service sign-up. */
class RegistrationControllerTest extends FunctionalTestCase
{
    public function testNewMemberCanRegisterAndIsLoggedIn(): void
    {
        $email = sprintf('phpunit-register-%s@example.test', $this->uniqueSuffix());

        $this->submitFormAt('/register', '/register', [
            'firstName'       => 'New',
            'lastName'        => 'Member',
            'email'           => $email,
            'password'        => 'correct-horse-battery',
            'passwordConfirm' => 'correct-horse-battery',
            'town'            => 'Cardigan',
        ]);
        self::assertResponseRedirects('/login/success');

        $user = $this->service(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $this->track($user);
        self::assertSame('Cardigan', $user->getTown());
        self::assertSame($user->getId(), $user->getCreatedBy()?->getId(), 'A self-registered member is their own creator.');

        $this->assertPageLoads('/account');
    }

    public function testMismatchedPasswordsAreRejected(): void
    {
        $this->submitFormAt('/register', '/register', [
            'firstName'       => 'New',
            'lastName'        => 'Member',
            'email'           => sprintf('phpunit-register-%s@example.test', $this->uniqueSuffix()),
            'password'        => 'correct-horse-battery',
            'passwordConfirm' => 'something-else',
        ]);

        self::assertResponseIsSuccessful();
        $this->assertSee("Those passwords don't match.");
    }

    public function testExistingEmailIsRejected(): void
    {
        $existing = $this->createUser();

        $this->submitFormAt('/register', '/register', [
            'firstName'       => 'New',
            'lastName'        => 'Member',
            'email'           => $existing->getEmail(),
            'password'        => 'correct-horse-battery',
            'passwordConfirm' => 'correct-horse-battery',
        ]);

        self::assertResponseIsSuccessful();
        $this->assertSee('An account already exists with that email address.');
    }
}
