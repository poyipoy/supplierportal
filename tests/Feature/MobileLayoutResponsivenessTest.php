<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MobileLayoutResponsivenessTest extends TestCase
{
    private function assertNoCompilerLeakage(string $html): void
    {
        $this->assertStringNotContainsString('@slot', $html, 'Blade compiler leaked @slot');
        $this->assertStringNotContainsString('@endslot', $html, 'Blade compiler leaked @endslot');
        $this->assertStringNotContainsString('<x-slot', $html, 'Blade compiler leaked <x-slot');
        $this->assertStringNotContainsString('</x-slot', $html, 'Blade compiler leaked </x-slot');
    }

    public function test_mobile_card_renders_cleanly_with_header_body_and_actions(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.mobile-card
    title="PR-202611-0042"
    subtitle="Periode Nov 2026"
    href="/purchasing/requisitions/pr-1"
>
    <x-slot:statusSlot>
        <x-ui.status-chip tone="warning">Bidding</x-ui.status-chip>
    </x-slot:statusSlot>

    <x-ui.mobile-card-row label="Supplier" value="3 Diundang" />
    <x-ui.mobile-card-row label="Total KG" value="14.500 kg" />

    <x-slot:actions>
        <x-ui.button size="sm" href="/purchasing/requisitions/pr-1">View Detail</x-ui.button>
    </x-slot:actions>
</x-ui.mobile-card>
BLADE);

        $this->assertStringContainsString('ui-mobile-card', $html);
        $this->assertStringContainsString('PR-202611-0042', $html);
        $this->assertStringContainsString('Periode Nov 2026', $html);
        $this->assertStringContainsString('Bidding', $html);
        $this->assertStringContainsString('Supplier', $html);
        $this->assertStringContainsString('3 Diundang', $html);
        $this->assertStringContainsString('14.500 kg', $html);
        $this->assertStringContainsString('View Detail', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_data_table_hybrid_cards_slot_renders_mode_toggle_and_cards(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.data-table title="Daftar Pengadaan">
    <x-slot:cards>
        <div class="test-mobile-card-item">Card Item 1</div>
    </x-slot:cards>
    <table class="table" id="testTable">
        <tbody><tr><td>Table Row 1</td></tr></tbody>
    </table>
</x-ui.data-table>
BLADE);

        $this->assertStringContainsString('ui-data-table__hybrid', $html);
        $this->assertStringContainsString('Mode Kartu (Mobile)', $html);
        $this->assertStringContainsString('test-mobile-card-item', $html);
        $this->assertStringContainsString('id="testTable"', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_action_bar_has_safe_area_style_and_mobile_stack(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.action-bar>
    <x-slot:left>
        <button type="button">Cancel</button>
    </x-slot:left>
    <x-slot:right>
        <button type="submit">Submit</button>
    </x-slot:right>
</x-ui.action-bar>
BLADE);

        $this->assertStringContainsString('ui-action-bar', $html);
        $this->assertStringContainsString('tw:flex-col-reverse', $html);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_toolbar_has_responsive_flex_stack(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.toolbar>
    <x-slot:search>
        <input type="search" name="q" placeholder="Cari..." />
    </x-slot:search>
    <x-slot:filters>
        <select name="status"><option value="all">Semua</option></select>
    </x-slot:filters>
</x-ui.toolbar>
BLADE);

        $this->assertStringContainsString('ui-toolbar', $html);
        $this->assertStringContainsString('tw:flex-col', $html);
        $this->assertStringContainsString('tw:items-stretch', $html);
        $this->assertNoCompilerLeakage($html);
    }

    public function test_mobile_material_editor_partial_renders_cleanly(): void
    {
        $html = Blade::render(<<<'BLADE'
@include('purchasing.pr._mobile_item_editor')
BLADE);

        $this->assertStringContainsString('id="prMobileItemSection"', $html);
        $this->assertStringContainsString('id="prMobileCardsList"', $html);
        $this->assertStringContainsString('id="prMobileEmptyState"', $html);
        $this->assertStringContainsString('id="btnOpenMobileMaterialModal"', $html);
        $this->assertStringContainsString('id="btnTogglePrTableView"', $html);
        $this->assertStringContainsString('id="mobileMaterialModal"', $html);
        $this->assertStringContainsString('id="mmMaterialSearch"', $html);
        $this->assertStringContainsString('id="mmShape"', $html);
        $this->assertStringContainsString('id="mmQuantity"', $html);
        $this->assertStringContainsString('id="btnSaveMobileMaterialItem"', $html);
        $this->assertNoCompilerLeakage($html);
    }
}
