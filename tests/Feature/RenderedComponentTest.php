<?php

namespace Tests\Feature;

use App\Http\Requests\LocalInvoice\StoreLocalInvoiceRequest;
use App\Models\LocalInvoiceDocument;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
        $this->assertStringContainsString('tw:bg-primary', $primary);
        $this->assertStringContainsString('tw:border-transparent', $primary);

        $secondary = Blade::render('<x-ui.button variant="secondary">Draft</x-ui.button>');
        $this->assertStringContainsString('tw:border-outline-variant', $secondary);
        $this->assertStringContainsString('tw:bg-secondary-container', $secondary);

        $outline = Blade::render('<x-ui.button variant="outline">Action</x-ui.button>');
        $this->assertStringContainsString('tw:border-outline', $outline);
        $this->assertStringContainsString('tw:bg-transparent', $outline);

        $ghost = Blade::render('<x-ui.button variant="ghost">Cancel</x-ui.button>');
        $this->assertStringContainsString('tw:border-transparent', $ghost);
        $this->assertStringContainsString('tw:bg-transparent', $ghost);
    }

    public function test_icon_button_variants_render_expected_contracts(): void
    {
        $outline = Blade::render('<x-ui.icon-button icon="search" label="Search" variant="outline" />');
        $this->assertStringContainsString('tw:border-outline', $outline);
        $this->assertStringContainsString('tw:bg-transparent', $outline);
        $this->assertStringContainsString('aria-label="Search"', $outline);

        $secondary = Blade::render('<x-ui.icon-button icon="filter" label="Filter" variant="secondary" />');
        $this->assertStringContainsString('tw:border-outline-variant', $secondary);
        $this->assertStringContainsString('tw:bg-secondary-container', $secondary);

        $ghost = Blade::render('<x-ui.icon-button icon="x" label="Close" variant="ghost" />');
        $this->assertStringContainsString('tw:border-transparent', $ghost);
        $this->assertStringContainsString('tw:bg-transparent', $ghost);
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

    public function test_searchable_select_search_field_has_an_id_without_becoming_a_submitted_value(): void
    {
        $html = Blade::render('<x-ui.searchable-select name="bank_select" id="bank_select" label="Bank" :options="[]" />');

        $this->assertStringContainsString('id="bank_select-search"', $html);
        $this->assertStringNotContainsString('name="bank_select-search"', $html);
        $this->assertStringContainsString('name="bank_select"', $html);
    }

    public function test_searchable_select_wraps_long_digits_in_options_and_meets_wcag_controls(): void
    {
        $options = [[
            'value' => '1',
            'label' => 'PO-LOC-2026-000123456 · Rp 12.500.000.000 · PR: PR-LOC-2026-999999',
            'sublabel' => 'Date: 12 Nov 2026 · Account 1234567890123456',
            'badge' => 'OPEN',
            'badgeTone' => 'success',
        ]];

        $html = Blade::render('<x-ui.searchable-select name="local_purchase_order_id" id="local_purchase_order_id" label="Local PO" :options="$options" />', [
            'options' => $options,
        ]);

        $this->assertNoCompilerLeakage($html);
        $this->assertStringContainsString('tw:min-w-0', $html);
        $this->assertStringContainsString('tw:z-dropdown', $html);
        $this->assertStringContainsString('tw:shadow-ui-2', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        $this->assertStringContainsString('tw:flex-wrap', $html);
        $this->assertStringContainsString('tw:shrink-0', $html);
        $this->assertStringNotContainsString('<span
                    @click.stop="clear()"', $html);
        $this->assertStringContainsString('id="local_purchase_order_id-trigger"', $html);
        $this->assertStringContainsString('width: 24px; height: 24px;', $html);
        $this->assertStringContainsString('@mousedown.prevent', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertDoesNotMatchRegularExpression('/<span[^>]*@click\.stop="clear\(\)"[^>]*>/', $html);
    }

    public function test_multi_select_handles_long_digits_layout_and_wcag_listbox_contract(): void
    {
        $options = [[
            'value' => '101',
            'label' => 'GR-3WAY-2026-000123456789',
            'description' => 'Plat Baja SPCC 1.2mm Sheet Cold Rolled Coil Industrial Spec',
            'sublabel' => 'Date: 12 Nov 2026',
            'amount' => 1250000000.50,
            'qty' => 10000.25,
            'uom' => 'PCS',
            'receipt_amount' => 1250000000.50,
            'quantityLabel' => '10.000,25',
        ]];

        $html = Blade::render('<x-ui.multi-select name="goods_receipt_ids[]" id="goods_receipt_ids" label="Whole Goods Receipt (GR)" :options="$options" />', [
            'options' => $options,
        ]);

        $this->assertNoCompilerLeakage($html);
        $this->assertStringContainsString('id="goods_receipt_ids-label"', $html);
        $this->assertStringContainsString('id="goods_receipt_ids-trigger"', $html);
        $this->assertStringContainsString('aria-labelledby="goods_receipt_ids-label goods_receipt_ids-trigger"', $html);
        $this->assertStringContainsString('aria-controls="goods_receipt_ids-listbox"', $html);
        $this->assertStringContainsString('id="goods_receipt_ids-search"', $html);
        $this->assertStringNotContainsString('name="goods_receipt_ids-search"', $html);
        $this->assertStringContainsString('for="goods_receipt_ids-search"', $html);
        $this->assertStringContainsString(':aria-activedescendant=', $html);
        $this->assertStringContainsString('id="goods_receipt_ids-listbox"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('tw:z-dropdown', $html);
        $this->assertStringContainsString('tw:shadow-ui-2', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
        $this->assertStringContainsString('tw:min-h-6', $html);
        $this->assertStringContainsString('tw:size-6', $html);
        $this->assertStringContainsString('width: 24px; height: 24px;', $html);
        $this->assertStringContainsString('@mousedown.prevent', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString("[data-option-index='\${this.highlightedIndex}']", $html);
        $this->assertDoesNotMatchRegularExpression('/<span[^>]*@click\.stop="clearAll\(\)"[^>]*>/', $html);
        // Options list container owns role="listbox", not the popover wrapper containing the search input.
        $this->assertMatchesRegularExpression('/id="goods_receipt_ids-listbox"[^>]*x-ref="optionsList"[^>]*role="listbox"|x-ref="optionsList"[^>]*id="goods_receipt_ids-listbox"[^>]*role="listbox"/', $html);
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

    public function test_sidebar_item_accessible_name_contains_its_visible_label(): void
    {
        // WCAG 2.5.3 Label in Name: voice-control users say what they see.
        $html = Blade::render('<x-ui.sidebar-item href="/s" icon="truck" label="Pengiriman dan Logistik">{{ "Pengiriman & Logistik" }}</x-ui.sidebar-item>');
        $this->assertStringContainsString('aria-label="Pengiriman &amp; Logistik"', $html);
        $this->assertStringContainsString('data-bs-title="Pengiriman dan Logistik"', $html, 'the tooltip keeps the longer label');

        $kept = Blade::render('<x-ui.sidebar-item href="/r" icon="clipboard-list" label="Purchase Requisition list">Purchase Requisition</x-ui.sidebar-item>');
        $this->assertStringContainsString('aria-label="Purchase Requisition list"', $kept, 'a label that already contains the visible text is kept');

        $disabled = Blade::render('<x-ui.sidebar-item href="/i" icon="file-plus" label="Submit a new bill" :disabled="true" disabled-hint="Audit overdue">Invoice</x-ui.sidebar-item>');
        $this->assertStringContainsString('aria-label="Invoice. Audit overdue"', $disabled);
    }

    public function test_auth_views_render_without_compiler_leakage(): void
    {
        $views = [
            'auth.login',
            'auth.forgot-password',
            'auth.confirm-password',
            'auth.rate-limited',
        ];

        foreach ($views as $view) {
            $html = view($view, [
                'returnUrl' => '/dashboard',
                'returnLabel' => 'Return to Dashboard',
                'turnstileRequired' => false,
                'supportEmail' => 'support@example.test', 'subject' => 'Assistance', 'template' => 'Template', 'mailto' => 'mailto:support@example.test',
                'turnstileSiteKey' => null,
                'errors' => new ViewErrorBag,
            ])->render();

            $this->assertNoCompilerLeakage($html);
        }
    }

    public function test_local_invoice_filters_render_cleanly(): void
    {
        app()->setLocale('id');
        $user = User::factory()->make(['id' => 1, 'role' => 'finance']);
        $this->actingAs($user);

        $mockSupplier = User::factory()->make(['id' => 999, 'name' => 'PT Sumber Logam']);
        $mockSupplier->setRelation('supplier', new Supplier(['company_name' => 'PT Sumber Logam Mandiri']));

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
        $supplierActor = User::factory()->make(['id' => 50, 'role' => 'supplier']);
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

    public function test_file_upload_single_mode_renders_ganti_berkas_and_omits_limit_reached_notice(): void
    {
        app()->setLocale('id');
        $html = Blade::render(<<<'BLADE'
<x-ui.file-upload
    name="import_file"
    id="prImportFile"
    label="Spreadsheet File"
    helper="XLSX, XLS, or CSV format; maximum 10 MB and up to 1,000 rows."
    accept=".xlsx,.xls,.csv"
/>
BLADE);

        $this->assertStringContainsString('Ganti Berkas', $html);
        $this->assertStringNotContainsString('Batas maksimal 5 berkas tercapai', $html);
        $this->assertStringNotContainsString('Tambah Berkas', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_file_upload_multi_mode_renders_tambah_berkas_and_limit_reached_notice(): void
    {
        app()->setLocale('id');
        $html = Blade::render(<<<'BLADE'
<x-ui.file-upload
    name="invoice"
    id="file_invoice"
    label="Berkas Invoice Fisik / Asli"
    :multiple="true"
    :max-files="5"
/>
BLADE);

        $this->assertStringContainsString('Tambah Berkas', $html);
        $this->assertStringContainsString('Batas maksimal 5 berkas tercapai', $html);
        $this->assertStringNotContainsString('Ganti Berkas', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_store_local_invoice_request_messages_differentiates_single_vs_multi_upload(): void
    {
        app()->setLocale('id');
        $requestSingle = new StoreLocalInvoiceRequest;
        $requestSingle->files->set('invoice', UploadedFile::fake()->create('inv.pdf', 6000));
        $messagesSingle = $requestSingle->messages();

        $this->assertSame(
            __('local_invoice.validation.file_size', ['type' => __('local_invoice.labels.invoice')]),
            $messagesSingle['invoice.max']
        );

        $requestMulti = new StoreLocalInvoiceRequest;
        $requestMulti->files->set('invoice', [
            UploadedFile::fake()->create('inv1.pdf', 100),
            UploadedFile::fake()->create('inv2.pdf', 100),
        ]);
        $messagesMulti = $requestMulti->messages();

        $this->assertSame(
            __('local_invoice.validation.file_count', [
                'type' => __('local_invoice.labels.invoice'),
                'count' => LocalInvoiceDocument::maxFilesFor('invoice'),
            ]),
            $messagesMulti['invoice.max']
        );
        $this->assertSame(
            __('local_invoice.validation.file_size', ['type' => __('local_invoice.labels.invoice')]),
            $messagesMulti['invoice.*.max']
        );
    }
}
