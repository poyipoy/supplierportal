<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
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
        $html = Blade::render('<x-ui.button><x-ui.icon name="save" /> Save</x-ui.button>');

        $this->assertStringContainsString('Save', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_icon_button_accepts_stateful_visual_slot(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.icon-button icon="panel-left" label="Toggle navigation">
    <x-slot:visual>
        <x-ui.icon name="panel-left-open" />
        <span class="sidebar-toggle-label">Collapse navigation</span>
    </x-slot:visual>
</x-ui.icon-button>
BLADE);

        $this->assertStringContainsString('aria-label="Toggle navigation"', $html);
        $this->assertStringContainsString('Collapse navigation', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_button_variants_render_expected_border_and_background_contracts(): void
    {
        $primary = Blade::render('<x-ui.button variant="primary">Submit</x-ui.button>');
        $this->assertStringContainsString('tw-bg-primary', $primary);
        $this->assertStringContainsString('tw-border-transparent', $primary);

        $secondary = Blade::render('<x-ui.button variant="secondary">Draft</x-ui.button>');
        $this->assertStringContainsString('tw-border-outline-variant', $secondary);
        $this->assertStringContainsString('tw-bg-secondary-container', $secondary);

        $outline = Blade::render('<x-ui.button variant="outline">Action</x-ui.button>');
        $this->assertStringContainsString('tw-border-outline', $outline);
        $this->assertStringContainsString('tw-bg-transparent', $outline);

        $ghost = Blade::render('<x-ui.button variant="ghost">Cancel</x-ui.button>');
        $this->assertStringContainsString('tw-border-transparent', $ghost);
        $this->assertStringContainsString('tw-bg-transparent', $ghost);
    }

    public function test_icon_button_variants_render_expected_contracts(): void
    {
        $outline = Blade::render('<x-ui.icon-button icon="search" label="Search" variant="outline" />');
        $this->assertStringContainsString('tw-border-outline', $outline);
        $this->assertStringContainsString('tw-bg-transparent', $outline);
        $this->assertStringContainsString('aria-label="Search"', $outline);

        $secondary = Blade::render('<x-ui.icon-button icon="filter" label="Filter" variant="secondary" />');
        $this->assertStringContainsString('tw-border-outline-variant', $secondary);
        $this->assertStringContainsString('tw-bg-secondary-container', $secondary);

        $ghost = Blade::render('<x-ui.icon-button icon="x" label="Close" variant="ghost" />');
        $this->assertStringContainsString('tw-border-transparent', $ghost);
        $this->assertStringContainsString('tw-bg-transparent', $ghost);
    }

    public function test_page_header_with_actions_and_buttons_renders_cleanly(): void
    {
        $template = <<<'BLADE'
<x-ui.page-header title="Dashboard">
    <x-slot:actions>
        <x-ui.button variant="primary"><x-ui.icon name="plus" /> New</x-ui.button>
        <x-ui.button variant="secondary"><x-ui.icon name="download" /> Export</x-ui.button>
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
        <x-ui.button><x-ui.icon name="arrow-down" /> Download</x-ui.button>
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

    public function test_sidebar_item_renders_active_tooltip_and_trailing_state_cleanly(): void
    {
        $template = <<<'BLADE'
<x-ui.sidebar-item href="/requisitions" icon="clipboard-list" :active="true" label="Purchase Requisition">
    Purchase Requisition
    <x-slot:trailing><span class="chat-badge">7</span></x-slot:trailing>
</x-ui.sidebar-item>
BLADE;

        $html = Blade::render($template);

        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('data-sidebar-tooltip', $html);
        $this->assertStringContainsString('data-bs-title="Purchase Requisition"', $html);
        $this->assertStringContainsString('sidebar-link-icon', $html);
        $this->assertStringContainsString('chat-badge', $html);
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
                'errors' => new ViewErrorBag,
            ])->render();

            $this->assertNoCompilerLeakage($html);
        }
    }

    public function test_local_invoice_filters_render_cleanly(): void
    {
        $user = \App\Models\User::factory()->make(['id' => 1, 'role' => 'finance']);
        $this->actingAs($user);

        $mockSupplier = \App\Models\User::factory()->make(['id' => 999, 'name' => 'PT Sumber Logam']);
        $mockSupplier->setRelation('supplier', new \App\Models\Supplier(['company_name' => 'PT Sumber Logam Mandiri']));

        $html = view('local-invoices.filters', [
            'suppliers' => collect([$mockSupplier]),
            'payments' => true,
        ])->render();

        $this->assertStringContainsString('Filter Lanjutan', $html);
        $this->assertStringContainsString('Kriteria Filter Lanjutan', $html);
        $this->assertStringContainsString('invoice-submitted-range', $html);
        $this->assertStringContainsString('invoice-due-range', $html);
        $this->assertStringContainsString('Organisasi Supplier', $html);
        $this->assertStringContainsString('PT Sumber Logam Mandiri', $html);
        $this->assertStringContainsString($mockSupplier->hash, $html);
        $this->assertStringContainsString('Hanya Jatuh Tempo', $html);
        $this->assertStringContainsString('Terapkan Filter', $html);
        $this->assertNoCompilerLeakage($html);

        // Verify supplier data isolation: supplier users cannot see supplier filter dropdown
        $supplierActor = \App\Models\User::factory()->make(['id' => 50, 'role' => 'supplier']);
        $this->actingAs($supplierActor);

        $supplierHtml = view('local-invoices.filters', [
            'suppliers' => collect([$mockSupplier]),
            'payments' => false,
        ])->render();

        $this->assertStringNotContainsString('Organisasi Supplier', $supplierHtml);
        $this->assertStringNotContainsString('invoice-supplier', $supplierHtml);
        $this->assertStringNotContainsString('PT Sumber Logam Mandiri', $supplierHtml);
        $this->assertNoCompilerLeakage($supplierHtml);
    }
}
