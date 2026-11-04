<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Purchasing > Material Claims and QC > Inspections share one structure: page header, pill tabs
 * (x-ui.tab-list), then a standard x-ui.data-table per pane. The DataTables chrome padding and font
 * only apply inside .ui-data-table, which is why the pages must not wrap their own bordered table.
 */
class ClaimAndInspectionListLayoutTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return [
            'purchasing claims' => ['purchasing', 'purchasing.claims.index', 'claimTabsContent', ['action' => 'actionTable', 'history' => 'historyTable'], 'action'],
            'qc inspections' => ['qc', 'qc.inspections.index', 'inspectionTabsContent', ['waiting' => 'waitingTable', 'history' => 'historyTable'], 'waiting'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_list_page_uses_pill_tabs_and_a_standard_data_table_per_pane(string $role, string $route, string $content, array $panes, string $active): void
    {
        foreach (['en', 'id'] as $locale) {
            $actor = User::factory()->create(['role' => $role]);
            $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);

            $html = $this->actingAs($actor)->get(route($route))->assertOk()->getContent();
            $xpath = $this->xpath($html);

            foreach ($panes as $pane => $table) {
                $selected = $pane === $active ? 'true' : 'false';
                $this->assertSame(1, $xpath->query('//button[@id="'.$pane.'-tab"][@role="tab"][@data-bs-toggle="tab"][@data-bs-target="#'.$pane.'"][@aria-controls="'.$pane.'"][@aria-selected="'.$selected.'"]')->length, "$locale: trigger for $pane");
                $this->assertSame(1, $xpath->query('//div[@id="'.$content.'"]/div[@id="'.$pane.'"][@role="tabpanel"][@aria-labelledby="'.$pane.'-tab"][@tabindex="0"]')->length, "$locale: pane $pane");
                $this->assertSame(1, $xpath->query('//div[@id="'.$pane.'"]//section[contains(@class, "ui-data-table")]//table[@id="'.$table.'"]')->length, "$locale: $table sits inside x-ui.data-table");
            }

            $this->assertSame(1, $xpath->query('//button[@role="tab"][@aria-selected="true"][@data-bs-toggle="tab"]')->length, "$locale: exactly one selected page tab");
            $this->assertSame(count($panes) - 1, $xpath->query('//button[@role="tab"][@data-bs-toggle="tab"][@aria-selected="false"][@tabindex="-1"]')->length, "$locale: inactive tabs leave the tab order");
            $this->assertSame(0, $xpath->query('//div[@id="'.$content.'"]//*[contains(@class, "table-responsive")]')->length, "$locale: no second bordered table box");
            $this->assertSame(0, $xpath->query('//*[contains(@class, "claim-nav-tabs") or contains(@class, "nav-tabs")]')->length, "$locale: no page-local tab styles");
        }
    }

    public function test_every_tab_shows_its_count_so_purchasing_history_matches_qc(): void
    {
        foreach ([['purchasing', 'purchasing.claims.index', ['action', 'history']], ['qc', 'qc.inspections.index', ['waiting', 'history']]] as [$role, $route, $panes]) {
            $html = $this->actingAs(User::factory()->create(['role' => $role]))->get(route($route))->assertOk()->getContent();
            $xpath = $this->xpath($html);

            foreach ($panes as $pane) {
                $this->assertSame('0', trim($xpath->evaluate('string(//button[@id="'.$pane.'-tab"]/span[last()])')), "$role: count chip on $pane");
            }
        }
    }

    public function test_the_qc_history_pane_keeps_its_export_filter_toolbar_and_modal_hook(): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => 'qc']))->get(route('qc.inspections.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//div[@id="history"]//select[@id="historyStatusFilter"]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="history"]//button[@id="resetHistoryStatusFilter"]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="inspectionExportLink"][contains(@class, "d-none")]')->length);
        $this->assertStringContainsString('table="#historyTable"', file_get_contents(resource_path('views/qc/inspections/index.blade.php')));
    }

    public function test_the_tab_list_component_marks_one_tab_selected_and_tones_only_non_zero_counts(): void
    {
        $html = Blade::render('<x-ui.tab-list label="Queues" active="b" :tabs="$tabs" />', ['tabs' => [
            ['id' => 'a', 'label' => 'Alpha', 'icon' => 'clock', 'count' => 3, 'tone' => 'error'],
            ['id' => 'b', 'label' => 'Beta', 'count' => 0, 'tone' => 'warning'],
            ['id' => 'c', 'label' => 'Gamma'],
        ]]);
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//div[@role="tablist"][@aria-label="Queues"]')->length);
        $this->assertSame(
            ['false', 'true', 'false'],
            array_map(fn (string $id) => $xpath->evaluate('string(//button[@id="'.$id.'-tab"]/@aria-selected)'), ['a', 'b', 'c'])
        );
        $this->assertSame(0, $xpath->query('//button[@id="b-tab"][@tabindex]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="a-tab"][@tabindex="-1"]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="a-tab"]//span[contains(@class, "tw:bg-error-container")]')->length);
        $this->assertSame(0, $xpath->query('//button[@id="b-tab"]//span[contains(@class, "tw:bg-warning-container")]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="b-tab"]//span[contains(@class, "tw:group-aria-selected:bg-primary-foreground")]')->length);
        $this->assertSame(0, $xpath->query('//button[@id="c-tab"]//span[contains(@class, "ui-tabular-nums")]')->length, 'no count, no chip');
        // Bootstrap's Tab plugin finds the trigger to deactivate through ".active"; without it the old
        // pane stays visible next to the new one.
        $this->assertSame(1, $xpath->query('//button[@role="tab"][contains(concat(" ", normalize-space(@class), " "), " active ")]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="b-tab"][contains(concat(" ", normalize-space(@class), " "), " active ")]')->length);
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
