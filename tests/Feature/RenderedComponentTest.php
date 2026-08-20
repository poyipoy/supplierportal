<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class RenderedComponentTest extends TestCase
{
    private function assertNoCompilerLeakage(string $html): void
    {
        $this->assertStringNotContainsString('@slot', $html, 'Blade compiler leaked @slot');
        $this->assertStringNotContainsString('@endslot', $html, 'Blade compiler leaked @endslot');
        $this->assertStringNotContainsString('<x-slot', $html, 'Blade compiler leaked <x-slot');
        $this->assertStringNotContainsString('</x-slot', $html, 'Blade compiler leaked </x-slot');
    }

    public function test_button_with_icon_renders_cleanly(): void
    {
        $html = Blade::render('<x-ui.button><i class="bi bi-save"></i> Save</x-ui.button>');
        
        $this->assertStringContainsString('Save', $html);
        $this->assertStringContainsString('bi-save', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_page_header_with_actions_and_buttons_renders_cleanly(): void
    {
        $template = <<<'BLADE'
<x-ui.page-header title="Dashboard">
    <x-slot:actions>
        <x-ui.button variant="primary"><i class="bi bi-plus"></i> New</x-ui.button>
        <x-ui.button variant="secondary"><i class="bi bi-export"></i> Export</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>
BLADE;

        $html = Blade::render($template);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('New', $html);
        $this->assertStringContainsString('Export', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_data_table_with_toolbar_and_filters_renders_cleanly(): void
    {
        $template = <<<'BLADE'
<x-ui.data-table id="test-table">
    <x-slot:toolbar>
        <x-ui.button><i class="bi bi-arrow-down"></i> Download</x-ui.button>
    </x-slot:toolbar>
    <x-slot:filters>
        <select><option>Status</option></select>
    </x-slot:filters>
    <thead>
        <tr><th>ID</th></tr>
    </thead>
</x-ui.data-table>
BLADE;

        $html = Blade::render($template);
        $this->assertStringContainsString('Download', $html);
        $this->assertStringContainsString('Status', $html);
        $this->assertStringContainsString('test-table', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_modal_renders_cleanly(): void
    {
        $template = <<<'BLADE'
<x-ui.dialog name="test-dialog" title="Test Dialog">
    <p>Dialog Content</p>
    <x-slot:actions>
        <x-ui.button>Close</x-ui.button>
    </x-slot:actions>
</x-ui.dialog>
BLADE;

        $html = Blade::render($template);
        $this->assertStringContainsString('Test Dialog', $html);
        $this->assertStringContainsString('Dialog Content', $html);
        $this->assertStringContainsString('Close', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_drawer_renders_cleanly(): void
    {
        $template = <<<'BLADE'
<x-ui.drawer name="test-drawer" title="Test Drawer" position="end">
    <p>Drawer Content</p>
</x-ui.drawer>
BLADE;

        $html = Blade::render($template);
        $this->assertStringContainsString('Test Drawer', $html);
        $this->assertStringContainsString('Drawer Content', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_status_chip_renders_cleanly(): void
    {
        $html = Blade::render('<x-ui.status-chip status="success">Active</x-ui.status-chip>');
        $this->assertStringContainsString('Active', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_auth_views_render_without_compiler_leakage(): void
    {
        $views = [
            'auth.login',
            'auth.forgot-password',
            'auth.confirm-password',
            'auth.verify-email',
            'auth.rate-limited',
        ];

        foreach ($views as $view) {
            $html = view($view, [
                'returnUrl' => '/dashboard',
                'returnLabel' => 'Return to Dashboard',
                'turnstileRequired' => false,
                'turnstileSiteKey' => null,
                'errors' => new \Illuminate\Support\ViewErrorBag(),
            ])->render();

            $this->assertNoCompilerLeakage($html);
        }
    }
}
