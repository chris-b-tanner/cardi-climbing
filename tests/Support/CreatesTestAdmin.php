<?php

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * For WebTestCase/KernelTestCase classes that need to act as a logged-in admin — finds or creates
 * a fixed test admin user rather than going through the real login form (loginUser() bypasses
 * password checking entirely, so no credentials are needed).
 */
trait CreatesTestAdmin
{
    private function findOrCreateAdmin(): User
    {
        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get('doctrine')->getManager();
        $repo = $em->getRepository(User::class);

        $admin = $repo->findOneBy(['email' => 'phpunit-admin@example.test']);
        if ($admin) {
            return $admin;
        }

        $admin = new User();
        $admin->setEmail('phpunit-admin@example.test');
        $admin->setPassword('unused — loginUser() bypasses password checking entirely');
        $admin->setRoles([User::ROLE_ADMIN]);
        $admin->setFirstName('PHPUnit');
        $admin->setLastName('Admin');

        $em->persist($admin);
        $em->flush();

        return $admin;
    }
}
