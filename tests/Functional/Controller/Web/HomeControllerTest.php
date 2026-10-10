<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

class HomeControllerTest extends FunctionalTestCase
{
    public function testHomepageLoads(): void
    {
        $this->assertPageLoads('/');
    }
}
