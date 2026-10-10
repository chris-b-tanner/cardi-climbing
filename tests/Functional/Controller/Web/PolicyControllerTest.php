<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

class PolicyControllerTest extends FunctionalTestCase
{
    public function testPoliciesPageLoads(): void
    {
        $this->assertPageLoads('/policies');
    }
}
