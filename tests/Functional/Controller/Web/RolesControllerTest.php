<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

class RolesControllerTest extends FunctionalTestCase
{
    public function testRolesPageLoads(): void
    {
        $this->assertPageLoads('/roles');
    }
}
