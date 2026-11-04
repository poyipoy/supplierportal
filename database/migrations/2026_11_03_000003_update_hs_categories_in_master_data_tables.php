<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RULE_CATEGORY_MAP = [
        'pdf-001-high-speed-round-d-ge-10' => 'high_speed_steel_round_bar',
        'pdf-002-alloy-round-d-10-165' => 'alloy_steel_round_bar_rolled',
        'pdf-004-alloy-flat-t-10-135-w-400-600' => 'alloy_steel_flat_rolled_rolled',
        'pdf-005-alloy-flat-t-3-135-w-400-600' => 'alloy_steel_flat_rolled_rolled',
        'pdf-006-alloy-flat-t-gt-135' => 'alloy_steel_flat_bar_forged',
        'pdf-008-alloy-round-d-gt-165' => 'alloy_steel_round_bar_forged',
        'pdf-010-alloy-flat-t-3-135-w-ge-600' => 'alloy_steel_flat_rolled_rolled',
        'pdf-012-alloy-flat-t-10-135-w-le-150' => 'alloy_steel_flat_bar_rolled',
        'pdf-013-alloy-flat-t-3-135-w-150-400' => 'alloy_steel_flat_rolled_strip_rolled',
        'pdf-014-high-speed-flat-t-ge-10-w-150-400' => 'high_speed_steel_flat_rolled_rolled',
        'pdf-015-carbon-round-d-16-250-l-gt-2000' => 'carbon_steel_round_bar',
        'pdf-017-carbon-flat-t-gt-10-w-gt-600' => 'carbon_steel_flat_bar',
        'pdf-019-carbon-round-d-ge-250-l-gt-2000' => 'carbon_steel_round_bar_forged',
        'pdf-021-carbon-flat-t-ge-250-w-gt-600' => 'carbon_steel_flat_bar_forged',
        'pdf-022-honed-hollow-od-ge-140-id-gt-50-selected' => 'honed_tube_steel_tube',
        'pdf-023-honed-hollow-od-ge-140-id-gt-50-rejected' => 'honed_tube_steel_tube',
        'pdf-024-strip-flat-w-le-400' => 'strip_steel_strip',
        'pdf-025-alloy-hollow-od-gt-165-id-gt-135-rejected' => 'alloy_steel_hollow_cold_drawn',
        'pdf-026-alloy-hollow-od-gt-165-id-gt-135-selected' => 'alloy_steel_hollow_hot_drawn',
        'pdf-027-carbon-flat-t-4-75-10-w-gt-600' => 'carbon_steel_flat_bar',
        'pdf-029-carbon-flat-t-3-4-75-w-gt-600' => 'carbon_steel_flat_bar',
    ];

    private const RULE_LEGACY_MAP = [
        'pdf-001-high-speed-round-d-ge-10' => 'high_speed_steel',
        'pdf-002-alloy-round-d-10-165' => 'alloy_steel',
        'pdf-004-alloy-flat-t-10-135-w-400-600' => 'alloy_steel',
        'pdf-005-alloy-flat-t-3-135-w-400-600' => 'alloy_steel',
        'pdf-006-alloy-flat-t-gt-135' => 'alloy_steel',
        'pdf-008-alloy-round-d-gt-165' => 'alloy_steel',
        'pdf-010-alloy-flat-t-3-135-w-ge-600' => 'alloy_steel',
        'pdf-012-alloy-flat-t-10-135-w-le-150' => 'alloy_steel',
        'pdf-013-alloy-flat-t-3-135-w-150-400' => 'alloy_steel',
        'pdf-014-high-speed-flat-t-ge-10-w-150-400' => 'high_speed_steel',
        'pdf-015-carbon-round-d-16-250-l-gt-2000' => 'carbon_steel',
        'pdf-017-carbon-flat-t-gt-10-w-gt-600' => 'carbon_steel',
        'pdf-019-carbon-round-d-ge-250-l-gt-2000' => 'carbon_steel',
        'pdf-021-carbon-flat-t-ge-250-w-gt-600' => 'carbon_steel',
        'pdf-022-honed-hollow-od-ge-140-id-gt-50-selected' => 'honed_tube_steel',
        'pdf-023-honed-hollow-od-ge-140-id-gt-50-rejected' => 'honed_tube_steel',
        'pdf-024-strip-flat-w-le-400' => 'strip_steel',
        'pdf-025-alloy-hollow-od-gt-165-id-gt-135-rejected' => 'alloy_steel',
        'pdf-026-alloy-hollow-od-gt-165-id-gt-135-selected' => 'alloy_steel',
        'pdf-027-carbon-flat-t-4-75-10-w-gt-600' => 'carbon_steel',
        'pdf-029-carbon-flat-t-3-4-75-w-gt-600' => 'carbon_steel',
    ];

    private const MATERIAL_CATEGORY_MAP = [
        'alloy_steel' => 'alloy_steel_round_bar_rolled',
        'high_speed_steel' => 'high_speed_steel_round_bar',
        'carbon_steel' => 'carbon_steel_round_bar',
        'honed_tube_steel' => 'honed_tube_steel_tube',
        'strip_steel' => 'strip_steel_strip',
    ];

    public function up(): void
    {
        if (Schema::hasTable('hs_code_rules')) {
            foreach (self::RULE_CATEGORY_MAP as $ruleKey => $category) {
                DB::table('hs_code_rules')
                    ->where('rule_key', $ruleKey)
                    ->update(['material_category' => $category]);
            }
        }

        if (Schema::hasTable('material_masters')) {
            foreach (self::MATERIAL_CATEGORY_MAP as $legacy => $specific) {
                DB::table('material_masters')
                    ->where('hs_category', $legacy)
                    ->update(['hs_category' => $specific]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hs_code_rules')) {
            foreach (self::RULE_LEGACY_MAP as $ruleKey => $legacyCategory) {
                DB::table('hs_code_rules')
                    ->where('rule_key', $ruleKey)
                    ->update(['material_category' => $legacyCategory]);
            }
        }

        if (Schema::hasTable('material_masters')) {
            foreach (self::MATERIAL_CATEGORY_MAP as $legacy => $specific) {
                DB::table('material_masters')
                    ->where('hs_category', $specific)
                    ->update(['hs_category' => $legacy]);
            }
        }
    }
};
