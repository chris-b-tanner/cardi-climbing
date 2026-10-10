<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\Support\FunctionalTestCase;

/** "Coming soon" stand-ins for admin sections that aren't built yet. */
class AdminPlaceholderControllerTest extends FunctionalTestCase
{
    public function testPlaceholderSectionLoadsForAdmin(): void
    {
        $this->loginAsAdmin();

        $this->assertPageLoads('/admin/calendar');
    }
}
