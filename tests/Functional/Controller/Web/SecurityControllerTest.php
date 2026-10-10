<?php

namespace App\Tests\Functional\Controller\Web;

use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;

/** The real login form (password checking included), and where each role lands afterwards. */
class SecurityControllerTest extends FunctionalTestCase
{
    public function testMemberCanLogInWithTheirPassword(): void
    {
        $member = $this->createUser(plainPassword: 'correct-horse-battery');

        $this->submitFormAt('/login', '/login', ['_username' => $member->getEmail(), '_password' => 'correct-horse-battery']);
        self::assertResponseRedirects('/login/success');

        $this->client->followRedirect();
        self::assertResponseRedirects('/account');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $member = $this->createUser(plainPassword: 'correct-horse-battery');

        $this->submitFormAt('/login', '/login', ['_username' => $member->getEmail(), '_password' => 'wrong-password']);
        self::assertResponseRedirects('/login');

        $this->get('/account');
        self::assertResponseRedirects('/login');
    }

    public function testTeamLandsOnTheAdminDashboard(): void
    {
        $this->loginAs($this->createUser(roles: [User::ROLE_TEAM]));

        $this->get('/login/success');
        self::assertResponseRedirects('/admin/dashboard');
    }
}
