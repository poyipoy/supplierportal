<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FilterBarComponentTest extends TestCase
{
    private function assertNoCompilerLeakage(string $html): void
    {
        $this->assertStringNotContainsString('@slot', $html, 'Blade compiler leaked @slot');
        $this->assertStringNotContainsString('@endslot', $html, 'Blade compiler leaked @endslot');
        $this->assertStringNotContainsString('<x-slot', $html, 'Blade compiler leaked <x-slot');
        $this->assertStringNotContainsString('</x-slot', $html, 'Blade compiler leaked </x-slot');
    }

    public function test_submit_mode_renders_progressive_tray_server_tabs_and_ssr_chips(): void
    {
        app()->setLocale('id');

        $items = [
            ['key' => 'status', 'label' => 'Status', 'value' => 'Aktif', 'removeUrl' => '/invoices'],
        ];

        $html = Blade::render(<<<'BLADE'
<x-ui.filter-bar
    id="sampleFilterForm"
    action="/invoices"
    :server-tabs="true"
    :sticky="true"
    aria-label="Kontrol filter"
    advanced-id="sampleMoreFilters"
    :advanced-count="2"
    :advanced-open="true"
    advanced-title="Kriteria Lanjutan"
    :has-active-filters="true"
    reset-url="/invoices"
>
    <x-slot:hidden>
        <input type="hidden" name="tab" value="all">
    </x-slot:hidden>
    <x-slot:search>
        <input type="search" name="q" id="sampleSearch" class="form-control form-control-sm ui-filter-bar__search-input" />
    </x-slot:search>
    <x-slot:filters>
        <select name="status" id="sampleStatus" class="form-select form-select-sm"><option value="">Semua</option></select>
    </x-slot:filters>
    <x-slot:advanced>
        <div>
            <label for="sampleSupplier">Supplier</label>
            <select name="supplier" id="sampleSupplier" class="form-select form-select-sm"><option value="">Semua</option></select>
        </div>
    </x-slot:advanced>
    <x-slot:chips>
        <x-ui.filter-chips :items="$items" clear-url="/invoices" :server-tabs="true" />
    </x-slot:chips>
</x-ui.filter-bar>
BLADE, ['items' => $items]);

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('id="sampleFilterForm"', $html);
        $this->assertStringContainsString('action="/invoices"', $html);
        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('data-server-tabs-form', $html);
        $this->assertStringContainsString('data-adasi-filter-bar', $html);
        $this->assertStringContainsString('data-filter-bar-mode="submit"', $html);
        $this->assertStringContainsString('ui-filter-bar--sticky', $html);
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('aria-controls="sampleMoreFilters"', $html);
        $this->assertStringContainsString('data-filter-bar-advanced-count', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('Filter Lanjutan', $html);
        $this->assertStringContainsString('Kriteria Lanjutan', $html);
        $this->assertStringContainsString('data-server-tabs-reset', $html);
        $this->assertStringContainsString('ui-filter-chips', $html);
        $this->assertStringContainsString('Filter aktif:', $html);
        $this->assertStringContainsString('Hapus semua', $html);
        $this->assertStringContainsString('Status:', $html);
        $this->assertStringContainsString('Aktif', $html);
        $this->assertStringContainsString('Terapkan', $html);
        $this->assertStringContainsString('Atur Ulang', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_live_mode_renders_non_form_container_and_empty_live_chips(): void
    {
        app()->setLocale('en');

        $html = Blade::render(<<<'BLADE'
<x-ui.filter-bar
    id="liveFilterBar"
    mode="live"
    reset-id="resetLiveFilters"
>
    <x-slot:search>
        <input type="search" id="liveSearch" class="form-control form-control-sm ui-filter-bar__search-input" />
    </x-slot:search>
    <x-slot:filters>
        <select id="liveStatus" class="form-select form-select-sm"><option value="">All</option></select>
    </x-slot:filters>
    <x-slot:chips>
        <x-ui.filter-chips id="liveFilterChips" />
    </x-slot:chips>
</x-ui.filter-bar>
BLADE);

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('type="submit"', $html);
        $this->assertStringContainsString('<div', $html);
        $this->assertStringContainsString('id="liveFilterBar"', $html);
        $this->assertStringContainsString('data-filter-bar-mode="live"', $html);
        $this->assertStringContainsString('id="resetLiveFilters"', $html);
        $this->assertStringContainsString('data-filter-bar-reset', $html);
        $this->assertStringContainsString('id="liveFilterChips"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-active-label="Active filters:"', $html);
        $this->assertStringContainsString('data-clear-label="Clear all"', $html);
        $this->assertStringContainsString('tw:hidden', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_embedded_variant_renders_embedded_class_without_sticky(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.filter-bar
    variant="embedded"
    :sticky="true"
    action="/queue"
    :has-active-filters="true"
    reset-url="/queue"
    :reset-attrs="['data-exchange-rates-link' => '']"
>
    <x-slot:search>
        <input type="search" name="q" class="form-control form-control-sm" />
    </x-slot:search>
</x-ui.filter-bar>
BLADE);

        $this->assertStringContainsString('ui-filter-bar--embedded', $html);
        $this->assertStringNotContainsString('ui-filter-bar--sticky', $html);
        $this->assertStringContainsString('ui-filter-bar__reset', $html);
        $this->assertStringContainsString('data-filter-bar-reset', $html);
        $this->assertStringContainsString('data-exchange-rates-link', $html);
        $this->assertNoCompilerLeakage($html);
    }
}
