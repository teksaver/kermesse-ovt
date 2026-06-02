<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class HomePageTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHomePageRespondsSuccessfully(): void
    {
        $result = $this->get('/');

        $result->assertOK();
        $result->assertSee('Kermesse');
        $result->assertSee('CodeIgniter 4');
    }
}
