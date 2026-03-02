<?php

namespace Tests\Unit;

use Tests\TestCase;

class CommissionTest extends TestCase
{
    public function test_commission_is_calculated_correctly(): void
    {
        $itemTotal  = 155000;
        $commRate   = 5;
        $commAmount = round($itemTotal * ($commRate / 100), 2);
        $this->assertEquals(7750.00, $commAmount);

        $this->assertEquals(1500.00, round(100000 * (1.5 / 100), 2));

        $this->assertEquals(80000.00, round(1000000 * (8 / 100), 2));

        $this->assertEquals(0.00, round(500000 * (0 / 100), 2));

        $this->assertEquals(333.33, round(33333 * (1 / 100), 2));
    }
}
