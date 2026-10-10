<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Certification;
use App\Entity\Declaration;
use App\Tests\Support\FunctionalTestCase;

/** Settings > Certifications: defining a certification type and its declarations. Admin only. */
class AdminCertificationTypeControllerTest extends FunctionalTestCase
{
    public function testTeamMembersCannotManageCertificationTypes(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/certifications');
    }

    public function testAdminCanCreateACertificationAndAddADeclaration(): void
    {
        $this->loginAsAdmin();
        $this->assertPageLoads('/admin/settings/certifications');

        $name = 'PHPUnit certification ' . $this->uniqueSuffix();
        $this->submitFormAt('/admin/settings/certifications/new', '/admin/settings/certifications/new', ['name' => $name]);
        $this->followRedirectAndAssertSee('Certification created.');

        $certification = $this->track($this->em()->getRepository(Certification::class)->findOneBy(['name' => $name]));
        self::assertNotNull($certification);

        $editUrl = '/admin/settings/certifications/' . $certification->getId() . '/edit';
        $this->submitFormAt($editUrl, '/admin/settings/certifications/' . $certification->getId() . '/declarations', [
            'text' => 'I agree to PHPUnit.',
        ]);
        $this->followRedirectAndAssertSee('Declaration added.');

        $declaration = $this->em()->getRepository(Declaration::class)->findOneBy(['certification' => $certification->getId()]);
        self::assertNotNull($declaration);
        $this->track($declaration); // tracked after the certification, so it's removed first

        $this->assertPageLoads('/admin/settings/certifications/' . $certification->getId() . '/preview');
    }
}
