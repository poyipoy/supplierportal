<?php

namespace Tests\Unit\Materials;

use App\Data\Materials\HsCodeConditionSet;
use PHPUnit\Framework\TestCase;

class HsCodeConditionSetTest extends TestCase
{
    public function test_inclusive_exclusive_boundaries_and_incomplete_evaluation(): void
    {
        $conditions = HsCodeConditionSet::fromArray([
            'thickness' => ['min' => 3, 'min_inclusive' => true, 'max' => 4.75, 'max_inclusive' => false],
            'width' => ['min' => 600, 'min_inclusive' => false, 'max' => null, 'max_inclusive' => true],
        ]);

        $this->assertTrue($conditions->evaluate(['thickness' => 3, 'width' => 601]));
        $this->assertFalse($conditions->evaluate(['thickness' => 4.75, 'width' => 601]));
        $this->assertFalse($conditions->evaluate(['thickness' => 4, 'width' => 600]));
        $this->assertNull($conditions->evaluate(['thickness' => 4]));
    }

    public function test_overlap_respects_open_boundaries(): void
    {
        $left = HsCodeConditionSet::fromArray([
            'thickness' => ['min' => 3, 'min_inclusive' => true, 'max' => 4.75, 'max_inclusive' => false],
        ]);
        $right = HsCodeConditionSet::fromArray([
            'thickness' => ['min' => 4.75, 'min_inclusive' => true, 'max' => 10, 'max_inclusive' => true],
        ]);
        $broad = HsCodeConditionSet::fromArray([
            'thickness' => ['min' => 4, 'min_inclusive' => true, 'max' => null, 'max_inclusive' => true],
        ]);

        $this->assertFalse($left->overlaps($right));
        $this->assertTrue($left->overlaps($broad));
    }
}
