<?php

namespace App\Tests\Functional\Controller\Web;

use App\Tests\Support\FunctionalTestCase;

class SurveyControllerTest extends FunctionalTestCase
{
    public function testSurveyPageLoads(): void
    {
        $this->assertPageLoads('/survey');
    }
}
