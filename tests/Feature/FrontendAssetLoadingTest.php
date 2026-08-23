<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FrontendAssetLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_datatables_assets_are_only_rendered_for_opted_in_pages(): void
    {
        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchasing.dashboard'))
            ->assertOk()
            ->assertDontSee('cdn.datatables.net/1.13.6', false)
            ->assertSee('code.jquery.com/jquery-3.7.1.min.js', false);

        $this->actingAs($purchasing)
            ->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->assertSee('cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css', false)
            ->assertSee('cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', false)
            ->assertSee('cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js', false);
    }

    public function test_every_blade_datatable_initializer_declares_the_asset_contract(): void
    {
        $viewRoot = resource_path('views');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot));
        $initializers = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! preg_match('/\.(?:DataTable|dataTable)\s*\(/', $contents)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
            $contractPath = $relativePath === 'admin/material-hs-code/_script.blade.php'
                ? resource_path('views/admin/material-hs-code/index.blade.php')
                : $file->getPathname();

            $this->assertStringContainsString(
                "@section('uses-datatables', true)",
                file_get_contents($contractPath),
                "DataTables assets were not enabled for {$relativePath}.",
            );

            $initializers[] = $relativePath;
        }

        $this->assertCount(13, $initializers);
    }

    public function test_unused_axios_runtime_and_dependency_are_removed(): void
    {
        $this->assertStringNotContainsString('axios', file_get_contents(base_path('package.json')));
        $this->assertStringNotContainsString('node_modules/axios', file_get_contents(base_path('package-lock.json')));
        $this->assertStringNotContainsString("import './bootstrap'", file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('window.axios', file_get_contents(resource_path('js/bootstrap.js')));
    }
}
