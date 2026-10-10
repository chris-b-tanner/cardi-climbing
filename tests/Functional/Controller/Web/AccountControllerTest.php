<?php

namespace App\Tests\Functional\Controller\Web;

use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;

/** A member's own "My account" page — profile details. (Certification completion is covered by Admin\AdminCertificationLifecycleTest.) */
class AccountControllerTest extends FunctionalTestCase
{
    public function testRequiresLogin(): void
    {
        $this->assertRequiresLogin('/account');
    }

    public function testMemberCanUpdateTheirProfile(): void
    {
        $member = $this->loginAsMember();

        $this->submitFormAt('/account', '/account', [
            'firstName'    => 'Updated',
            'town'         => 'Aberteifi',
            'dateOfBirth'  => '1990-05-17',
        ]);
        $this->followRedirectAndAssertSee('Your details have been updated.');

        $this->em()->clear();
        $reloaded = $this->em()->find(User::class, $member->getId());
        self::assertSame('Updated', $reloaded->getFirstName());
        self::assertSame('Aberteifi', $reloaded->getTown());
        self::assertSame('1990-05-17', $reloaded->getDateOfBirth()?->format('Y-m-d'));
    }

    public function testReturnToCannotRedirectOffSite(): void
    {
        $this->loginAsMember();

        $this->submitFormAt('/account', '/account', ['returnTo' => '//evil.example/phish']);
        self::assertResponseRedirects('/account');
    }
}
