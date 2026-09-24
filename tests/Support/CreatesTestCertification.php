<?php

namespace App\Tests\Support;

use App\Entity\Certification;
use App\Entity\Declaration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Setup data for exercising the certification *process* (start → complete → approve) — a
 * certification with its declarations, built fresh per test so nothing here depends on real
 * admin-maintained cert types. Deliberately doesn't test creating/editing a Certification or
 * Declaration itself (that's Settings > Certifications' own job); this just gives the process
 * something realistic to run against.
 */
trait CreatesTestCertification
{
    /** @return Certification A persisted certification with one Declaration per {declarationTexts} attached, in order. */
    private function createCertification(array $declarationTexts = ['I confirm I have read and understood the safety briefing.']): Certification
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $certification = new Certification();
        $certification->setName(sprintf('PHPUnit Induction %s', bin2hex(random_bytes(4))));
        $certification->setDescription('Fixture certification for PHPUnit functional tests.');
        $em->persist($certification);

        foreach (array_values($declarationTexts) as $i => $text) {
            $declaration = new Declaration();
            $declaration->setCertification($certification);
            $declaration->setText($text);
            $declaration->setSortOrder($i);
            $em->persist($declaration);
        }

        $em->flush();

        // Declaration owns the relation (it carries the FK) — setting that owning side persists
        // correctly, but Doctrine never syncs the inverse $certification->declarations collection
        // in memory for you. Refresh so getDeclarations() actually reflects what was just saved,
        // the same as it would if this certification had been loaded fresh from the DB.
        $em->refresh($certification);

        return $certification;
    }

    /** Removes {certificationId} and any declarations still pointing at it — call from tearDown() alongside removing the users/records that used it. */
    private function removeCertification(int $certificationId): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        foreach ($em->getRepository(Declaration::class)->findBy(['certification' => $certificationId]) as $declaration) {
            $em->remove($declaration);
        }

        $certification = $em->getRepository(Certification::class)->find($certificationId);
        if ($certification) {
            $em->remove($certification);
        }

        $em->flush();
    }
}
