<?php

namespace Tests\Unit;

use App\Support\NumberFormat;
use PHPUnit\Framework\TestCase;

class NumberFormatTest extends TestCase
{
    public function test_max_decimals_trims_unneeded_zeroes_after_rounding(): void
    {
        $this->assertSame('10', NumberFormat::maxDecimals(10));
        $this->assertSame('10.5', NumberFormat::maxDecimals(10.5));
        $this->assertSame('10.56', NumberFormat::maxDecimals(10.556));
        $this->assertSame('-', NumberFormat::maxDecimals(null));
    }
}
