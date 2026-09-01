<?php

namespace Tests\Unit\Materials;

use App\Support\Materials\MaterialDimensionRules;
use PHPUnit\Framework\TestCase;

class MaterialDimensionRulesTest extends TestCase
{
    public function test_empty_or_null_values_are_considered_vacuously_valid(): void
    {
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair(null, null));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair('', ''));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair(null, 100));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair(50, null));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair('', 100));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair(50, ''));
    }

    public function test_non_numeric_values_are_invalid(): void
    {
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair('abc', 100));
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair(50, 'xyz'));
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair([], 100));
    }

    public function test_inner_must_be_strictly_less_than_outer(): void
    {
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair(50, 100));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair('50', '100'));
        $this->assertTrue(MaterialDimensionRules::hasValidHollowDiameterPair(49.9999, 50.0));

        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair(100, 100));
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair('100', '100'));
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair(100.0, 100.0));

        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair(100, 50));
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair('100', '50'));
        $this->assertFalse(MaterialDimensionRules::hasValidHollowDiameterPair(50.0001, 50.0));
    }
}
