<?php

namespace Database\Seeders;

use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Models\User;
use App\Services\Pricing\ReferencePriceRuleImportService;
use Illuminate\Database\Seeder;

class ReferencePriceRulesInitialSeeder extends Seeder
{
    public function run(): void
    {
        if (ReferencePriceRule::query()->exists()) {
            return;
        }

        $admin = User::where('role', 'admin')->first()
            ?? User::where('role', 'purchasing')->first()
            ?? User::first()
            ?? User::factory()->create([
                'name' => 'System Initializer',
                'username' => 'system_init',
                'email' => 'system@adasi.test',
                'role' => 'admin',
            ]);

        $service = app(ReferencePriceRuleImportService::class);
        $fixturePath = base_path('tests/Fixtures/Kategori Range.xlsx');

        if (file_exists($fixturePath)) {
            $parsed = $service->parseWorkbook($fixturePath, 'Kategori');
            $import = ReferencePriceImport::create([
                'uploaded_by' => $admin->id,
                'source_file_name' => 'Kategori Range.xlsx',
                'private_storage_path' => 'tests/Fixtures/Kategori Range.xlsx',
                'source_file_hash' => hash_file('sha256', $fixturePath),
                'source_sheet' => 'Kategori',
                'import_mode' => ReferencePriceImport::MODE_REPLACE,
                'status' => ReferencePriceImport::STATUS_PREVIEWED,
                'parsed_count' => count($parsed),
            ]);

            $service->commit($import, $parsed, $admin);

            return;
        }

        // Fallback default definitions for the 11 categories
        $defaultCategories = [
            ['category_key' => '1-15', 'category_name' => '1-15', 'dimension_min' => 1, 'dimension_max' => 15, 'sort_order' => 1],
            ['category_key' => '16-50', 'category_name' => '16-50', 'dimension_min' => 16, 'dimension_max' => 50, 'sort_order' => 2],
            ['category_key' => '51-100', 'category_name' => '51-100', 'dimension_min' => 51, 'dimension_max' => 100, 'sort_order' => 3],
            ['category_key' => '101-150', 'category_name' => '101-150', 'dimension_min' => 101, 'dimension_max' => 150, 'sort_order' => 4],
            ['category_key' => '151-200', 'category_name' => '151-200', 'dimension_min' => 151, 'dimension_max' => 200, 'sort_order' => 5],
            ['category_key' => '201-250', 'category_name' => '201-250', 'dimension_min' => 201, 'dimension_max' => 250, 'sort_order' => 6],
            ['category_key' => '251-300', 'category_name' => '251-300', 'dimension_min' => 251, 'dimension_max' => 300, 'sort_order' => 7],
            ['category_key' => '301-400', 'category_name' => '301-400', 'dimension_min' => 301, 'dimension_max' => 400, 'sort_order' => 8],
            ['category_key' => '401-500', 'category_name' => '401-500', 'dimension_min' => 401, 'dimension_max' => 500, 'sort_order' => 9],
            ['category_key' => '501-600', 'category_name' => '501-600', 'dimension_min' => 501, 'dimension_max' => 600, 'sort_order' => 10],
            ['category_key' => '601-700', 'category_name' => '601-700', 'dimension_min' => 601, 'dimension_max' => 700, 'sort_order' => 11],
        ];

        $import = ReferencePriceImport::create([
            'uploaded_by' => $admin->id,
            'source_file_name' => 'Kategori Range (Default Initial)',
            'private_storage_path' => 'system/initial',
            'source_file_hash' => hash('sha256', 'initial_categories'),
            'source_sheet' => 'Kategori',
            'import_mode' => ReferencePriceImport::MODE_REPLACE,
            'status' => ReferencePriceImport::STATUS_PREVIEWED,
            'parsed_count' => count($defaultCategories),
        ]);

        $service->commit($import, $defaultCategories, $admin);
    }
}
