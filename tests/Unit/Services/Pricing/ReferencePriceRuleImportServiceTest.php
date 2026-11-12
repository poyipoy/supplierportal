<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Models\ReferencePriceRuleSet;
use App\Models\ReferencePriceSetting;
use App\Models\User;
use App\Services\Pricing\ReferencePriceRuleImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferencePriceRuleImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReferencePriceRuleImportService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReferencePriceRuleImportService::class);
        $this->user = User::factory()->create(['role' => 'purchasing']);
    }

    public function test_it_parses_actual_kategori_range_excel_into_11_categories(): void
    {
        $path = base_path('tests/Fixtures/Kategori Range.xlsx');
        $this->assertFileExists($path);

        $parsed = $this->service->parseWorkbook($path, 'Kategori');

        $this->assertCount(11, $parsed);
        $expectedKeys = [
            '1-15', '16-50', '51-100', '101-150', '151-200',
            '201-250', '251-300', '301-400', '401-500', '501-600', '601-700',
        ];
        $this->assertSame($expectedKeys, array_column($parsed, 'category_key'));

        $this->assertEquals(1, $parsed[0]['dimension_min']);
        $this->assertEquals(15, $parsed[0]['dimension_max']);
        $this->assertEquals(601, $parsed[10]['dimension_min']);
        $this->assertEquals(700, $parsed[10]['dimension_max']);
    }

    public function test_it_detects_gap_or_invalid_sequence_in_excel(): void
    {
        $invalidData = [
            ['category_key' => '1-10', 'dimension_min' => 1, 'dimension_max' => 10],
            ['category_key' => '15-20', 'dimension_min' => 15, 'dimension_max' => 20],
        ];

        $errors = $this->service->validateRuleIntervals($invalidData);
        // It has a gap between 10 and 15, which is reported as warning or info, but not overlap
        $this->assertEmpty($errors);

        $overlappingData = [
            ['category_key' => '1-15', 'dimension_min' => 1, 'dimension_max' => 15],
            ['category_key' => '10-25', 'dimension_min' => 10, 'dimension_max' => 25],
        ];
        $overlapErrors = $this->service->validateRuleIntervals($overlappingData);
        $this->assertNotEmpty($overlapErrors);
        $this->assertStringContainsString('overlap', strtolower($overlapErrors[0]));
    }

    public function test_replace_mode_replaces_all_categories_in_preview(): void
    {
        // Seed an initial set with 2 categories
        $initialSet = ReferencePriceRuleSet::create([
            'version_no' => 1,
            'status' => ReferencePriceRuleSet::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'activated_by' => $this->user->id,
            'activated_at' => now(),
        ]);
        ReferencePriceRule::create([
            'rule_set_id' => $initialSet->id,
            'category_key' => 'old-category',
            'category_name' => 'Old Category',
            'dimension_min' => 1,
            'dimension_max' => 10,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
        ]);
        ReferencePriceSetting::create([
            'active_rule_set_id' => $initialSet->id,
            'updated_by' => $this->user->id,
        ]);

        $newRules = [
            ['category_key' => '1-15', 'category_name' => '1-15', 'dimension_min' => 1, 'dimension_max' => 15, 'sort_order' => 1],
        ];

        $preview = $this->service->buildPreview($newRules, ReferencePriceRuleSet::MODE_REPLACE, $initialSet);

        $this->assertTrue($preview['valid']);
        $this->assertCount(1, $preview['new']);
        $this->assertCount(1, $preview['removed']);
        $this->assertSame('old-category', $preview['removed'][0]['category_key']);
    }

    public function test_merge_mode_preserves_non_overlapping_categories(): void
    {
        $initialSet = ReferencePriceRuleSet::create([
            'version_no' => 1,
            'status' => ReferencePriceRuleSet::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'activated_by' => $this->user->id,
            'activated_at' => now(),
        ]);
        ReferencePriceRule::create([
            'rule_set_id' => $initialSet->id,
            'category_key' => '1-15',
            'category_name' => '1-15',
            'dimension_min' => 1,
            'dimension_max' => 15,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
        ]);
        ReferencePriceRule::create([
            'rule_set_id' => $initialSet->id,
            'category_key' => '16-50',
            'category_name' => '16-50',
            'dimension_min' => 16,
            'dimension_max' => 50,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
        ]);

        // Merge a new category 51-100
        $newRules = [
            ['category_key' => '51-100', 'category_name' => '51-100', 'dimension_min' => 51, 'dimension_max' => 100, 'sort_order' => 3],
        ];

        $preview = $this->service->buildPreview($newRules, ReferencePriceRuleSet::MODE_MERGE, $initialSet);

        $this->assertTrue($preview['valid']);
        $this->assertCount(1, $preview['new']);
        $this->assertCount(0, $preview['removed']);
        $this->assertCount(2, $preview['unchanged']);
    }

    public function test_merge_mode_detects_overlap_conflicts(): void
    {
        $initialSet = ReferencePriceRuleSet::create([
            'version_no' => 1,
            'status' => ReferencePriceRuleSet::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'activated_by' => $this->user->id,
            'activated_at' => now(),
        ]);
        ReferencePriceRule::create([
            'rule_set_id' => $initialSet->id,
            'category_key' => '16-50',
            'category_name' => '16-50',
            'dimension_min' => 16,
            'dimension_max' => 50,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
        ]);

        // Merge an overlapping category with different key: '10-30'
        $newRules = [
            ['category_key' => '10-30', 'category_name' => '10-30', 'dimension_min' => 10, 'dimension_max' => 30, 'sort_order' => 1],
        ];

        $preview = $this->service->buildPreview($newRules, ReferencePriceRuleSet::MODE_MERGE, $initialSet);

        $this->assertFalse($preview['valid']);
        $this->assertNotEmpty($preview['conflicts']);
    }

    public function test_commit_creates_new_version_and_sets_active_atomically(): void
    {
        $path = base_path('tests/Fixtures/Kategori Range.xlsx');
        $parsed = $this->service->parseWorkbook($path, 'Kategori');

        $import = ReferencePriceImport::create([
            'uploaded_by' => $this->user->id,
            'source_file_name' => 'Kategori Range.xlsx',
            'private_storage_path' => 'fixtures/Kategori Range.xlsx',
            'source_file_hash' => hash_file('sha256', $path),
            'source_sheet' => 'Kategori',
            'import_mode' => ReferencePriceRuleSet::MODE_REPLACE,
            'status' => ReferencePriceImport::STATUS_PREVIEWED,
            'parsed_count' => count($parsed),
        ]);

        $newSet = $this->service->commit($import, $parsed, $this->user);

        $this->assertEquals(1, $newSet->version_no);
        $this->assertEquals(ReferencePriceRuleSet::STATUS_ACTIVE, $newSet->status);
        $this->assertCount(11, $newSet->rules);

        $activeSetting = ReferencePriceSetting::first();
        $this->assertNotNull($activeSetting);
        $this->assertEquals($newSet->id, $activeSetting->active_rule_set_id);
    }
}
