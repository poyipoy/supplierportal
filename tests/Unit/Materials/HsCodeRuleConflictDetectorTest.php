<?php

namespace Tests\Unit\Materials;

use App\Models\HsCodeRule;
use App\Services\Materials\HsCodeRuleConflictDetector;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsCodeRuleConflictDetectorTest extends TestCase
{
    use RefreshDatabase;

    private HsCodeRuleConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->detector = app(HsCodeRuleConflictDetector::class);
    }

    public function test_only_same_priority_different_code_overlap_blocks_activation(): void
    {
        $blocking = $this->candidate('9999.99.99', 100);
        $this->assertTrue($this->detector->hasBlockingConflict($blocking));
        $this->assertTrue($this->detector->overlapsFor($blocking)->contains(
            fn (array $overlap) => $overlap['type'] === 'exact_conflict' && $overlap['blocks_activation']
        ));

        $differentPriority = $this->candidate('9999.99.99', 50);
        $this->assertFalse($this->detector->hasBlockingConflict($differentPriority));

        $sameCode = $this->candidate('7228.30.10', 100);
        $this->assertFalse($this->detector->hasBlockingConflict($sameCode));
        $this->assertTrue($this->detector->overlapsFor($sameCode)->contains(
            fn (array $overlap) => $overlap['type'] === 'exact_duplicate' && ! $overlap['blocks_activation']
        ));
    }

    private function candidate(string $hsCode, int $priority): HsCodeRule
    {
        return new HsCodeRule([
            'rule_key' => 'candidate-'.$priority.'-'.$hsCode,
            'hs_code' => $hsCode,
            'material_category' => 'alloy_steel',
            'shape' => 'Round',
            'conditions' => [
                'd_outer' => [
                    'min' => 10,
                    'min_inclusive' => true,
                    'max' => 165,
                    'max_inclusive' => true,
                ],
            ],
            'priority' => $priority,
            'status' => HsCodeRule::STATUS_ACTIVE,
            'source_refs' => [],
        ]);
    }
}
