<?php

namespace Tests\Feature\Procurement;

use App\Models\AnnualExchangeRate;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Support\HashidCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gate 1 C1 / AC3 / C8: exactly one row per (PR Item, participating supplier), sources never
 * collapsed, no raw ids in the payload, a constant query count, and escaped user text.
 */
class HistoricalPrIdentityTest extends TestCase
{
    use QuotationHistoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationHistory();
        foreach (['USD' => 17700, 'JPY' => 110, 'CNY' => 2250] as $currency => $rate) {
            AnnualExchangeRate::create(['year' => 2026, 'revision' => 1, 'currency' => $currency, 'rate_to_idr' => $rate, 'created_by' => $this->purchasingUser->id]);
        }
    }

    public function test_one_row_per_item_and_participating_supplier_with_sources_kept(): void
    {
        [$sa, $sb, $se, $draft] = [$this->supplierNamed('S-A'), $this->supplierNamed('S-B'), $this->supplierNamed('S-E'), $this->supplierNamed('Draft only')];
        $prG = $this->prAt('2026-10-01', [self::skd(), self::skd(50, 400, 1500), self::round('S45C', 60)]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80, 3.90, 2.10]);
        $this->quote($prG, $sb, '2026-10-15', 'USD', 'USD', [3.70]); // items 2-3 not offered
        $this->quote($prG, $se, '2026-10-12', 'USD', 'USD', [4.00]);
        $this->quote($prG, $se, '2026-10-14', 'USD', 'USD', [4.10, 3.95, 2.20], Quotation::STATUS_ACCEPTED);
        $this->quote($prG, $draft, '2026-10-14', 'USD', 'USD', [1.00], Quotation::STATUS_DRAFT);

        $rows = $this->payload($prG)['quotationHistory']['rows'];

        $this->assertCount(9, $rows, '3 items x 3 participating suppliers; the draft-only supplier does not participate.');
        $keys = array_map(fn (array $row) => $row['pr_item_hash'].'|'.$row['supplier']['hash'], $rows);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $ambiguous = array_values(array_filter($rows, fn (array $row) => $row['supplier']['name'] === 'S-E'));
        $this->assertSame(['ambiguous', 'ambiguous', 'ambiguous'], array_column($ambiguous, 'status'));
        $this->assertSame([2, 2, 2], array_map(fn (array $row) => count($row['sources']), $ambiguous));
        $this->assertSame(['not_offered', 'not_offered'], array_column(array_values(array_filter($rows, fn (array $row) => $row['supplier']['name'] === 'S-B' && $row['status'] !== 'insufficient_history')), 'status'));
    }

    public function test_payload_exposes_hashes_only(): void
    {
        $sa = $this->supplierNamed('S-A');
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($prior, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->payload($prG)['quotationHistory']['rows'][0];

        foreach (['pr_item_id', '_current_line'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        $this->assertSame(['hash', 'name'], array_keys($row['supplier']));
        $this->assertArrayNotHasKey('line_id', $row['current']);
        $this->assertArrayNotHasKey('line_id', $row['previous']);
        $this->assertStringNotContainsString('/quotations/'.Quotation::query()->min('id').'?', $row['previous']['detail_url']);
    }

    public function test_supplier_filter_keeps_one_row_per_item(): void
    {
        [$sa, $sb] = [$this->supplierNamed('S-A'), $this->supplierNamed('S-B')];
        $prG = $this->prAt('2026-10-01', [self::skd(), self::round('S45C', 60)]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80, 2.10]);
        $this->quote($prG, $sb, '2026-10-15', 'USD', 'USD', [3.70, 2.00]);

        $rows = $this->payload($prG, ['supplier_id' => $sb->getRouteKey()])['quotationHistory']['rows'];

        $this->assertSame(['S-B', 'S-B'], array_column(array_column($rows, 'supplier'), 'name'));
    }

    public function test_query_count_does_not_grow_with_items_suppliers_or_history(): void
    {
        $small = $this->scenario(2, 2, 1);
        $large = $this->scenario(6, 4, 3);

        $this->assertSame($this->countQueries($small), $this->countQueries($large));
    }

    public function test_supplier_names_and_notes_are_escaped(): void
    {
        $sa = $this->supplierNamed('S-A');
        Supplier::query()->where('user_id', $sa->id)->update(['company_name' => '<script>alert(1)</script>']);
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $quotation = $this->quote($prior, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $quotation->items()->update(['notes' => '<img src=x onerror=alert(2)>']);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $html = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.comparison.historical', ['pr_id' => $prG->getRouteKey()]))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        $drawer = $this->actingAs($this->purchasingUser)->getJson(route('purchasing.comparison.historical', [
            'pr_id' => $prG->getRouteKey(), 'pr_item' => HashidCodec::encode($prG->items[0]->id),
            'supplier_id' => $sa->getRouteKey(), 'view' => 'json', 'history' => 1,
        ]))->assertOk();
        // The price-only drawer does not carry supplier notes; everything it shows is set with textContent (tests/js).
        $this->assertArrayNotHasKey('notes', $drawer->json('records.0'));
        $this->assertStringNotContainsString('onerror', $drawer->getContent());
    }

    public function test_another_thickness_renders_no_price_and_labelled_dimension_diffs(): void
    {
        [$sa, $sb] = [$this->supplierNamed('S-A'), $this->supplierNamed('S-B')];
        $prior = $this->prAt('2026-06-01', [['material' => 'P20', 'shape' => 'Flat', 'dims' => ['thickness' => 405, 'width' => 610, 'length' => 4000]], self::skd()]);
        $this->quote($prior, $sa, '2026-06-10', 'USD', 'USD', [38.73, 3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [['material' => 'P20', 'shape' => 'Flat', 'dims' => ['thickness' => 25, 'width' => 900, 'length' => 2300]], self::skd(30, 200, 1000)]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [20.90, 3.80]);
        $this->quote($prG, $sb, '2026-10-15', 'USD', 'USD', [20.50, 3.70]);

        $html = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.comparison.historical', ['pr_id' => $prG->getRouteKey()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(trans_choice('purchasing.quotation_history.different_spec_message', 1, ['count' => 1, 'spec' => 'Thickness 25'])), $html);
        $this->assertStringNotContainsString('38.73', $html);
        $this->assertStringNotContainsString('38,73', $html);
        $this->assertStringContainsString(e(__('purchasing.quotation_history.spec_diff_line', ['label' => 'Width', 'current' => '200', 'previous' => '300'])), $html);
        $this->assertStringContainsString(e(__('purchasing.quotation_history.indicative_spec', ['fields' => 'Width, Length'])), $html);

        // Quotation history opens as a centered modal dialog, not a side drawer.
        $this->assertStringContainsString("open-ui-dialog', { detail: 'historical-quotation-dialog'", $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringNotContainsString('historical-quotation-drawer', $html);

        // Empty chart state is an overlay inside the plot box (the tw:h-72 relative frame), centered.
        $this->assertMatchesRegularExpression('/<div class="tw:relative tw:w-full tw:h-72">\s*<canvas id="historicalPrChart"[^>]*>.*?<\/canvas>\s*<div[^>]*id="historicalPrChartEmpty"/s', $html);
        preg_match('/<div[^>]*id="historicalPrChartEmpty"[^>]*>/', $html, $empty);
        foreach (['tw:absolute', 'tw:inset-0', 'tw:items-center', 'tw:justify-center', 'role="status"', 'hidden'] as $needle) {
            $this->assertStringContainsString($needle, $empty[0]);
        }
    }

    public function test_drawer_returns_a_price_only_history_split_by_size(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $p2 = $this->prAt('2026-07-01', [self::skd(50)]);
        $this->quote($p2, $sa, '2026-07-10', 'USD', 'USD', [3.90], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $drawer = $this->payload($prG, ['history' => '1', 'pr_item' => HashidCodec::encode($prG->items[0]->id), 'supplier_id' => $sa->getRouteKey()]);

        $this->assertSame([2, 1, 1], [$drawer['total'], $drawer['comparable_total'], $drawer['other_total']]);
        $this->assertNotNull($drawer['current']['price']);
        $this->assertSame(1, $drawer['summary']['count']);
        [$same, $other] = $drawer['records'];
        $this->assertSame([true, true, '5.5556'], [$same['spec_equal'], $same['selected'], $same['diff_pct']]);
        $this->assertSame([false, null], [$other['spec_equal'], $other['diff_pct']]);
        $this->assertSame(
            ['pr_number', 'po_number', 'date', 'status', 'status_label', 'selected', 'spec_equal', 'key', 'price', 'original', 'rate', 'diff_pct', 'diff_display', 'diff_converted', 'basis_note', 'detail_url'],
            array_keys($same),
            'Only what a price comparison needs; logistics and amounts stay on the quotation page.',
        );
    }

    private function payload($pr, array $query = []): array
    {
        return $this->actingAs($this->purchasingUser)
            ->getJson(route('purchasing.comparison.historical', ['pr_id' => $pr->getRouteKey(), 'view' => 'json', ...$query]))
            ->assertOk()
            ->json();
    }

    private function scenario(int $items, int $suppliers, int $historyPerSupplier)
    {
        $specs = array_map(fn (int $index) => self::skd(10 + $index), range(1, $items));
        $users = array_map(fn (int $index) => $this->supplierNamed('Q'.$items.'-'.$index), range(1, $suppliers));
        for ($history = 0; $history < $historyPerSupplier; $history++) {
            $prior = $this->prAt('2026-0'.(6 + $history).'-01', $specs);
            foreach ($users as $user) {
                $this->quote($prior, $user, '2026-0'.(6 + $history).'-10', 'USD', 'USD', array_fill(0, $items, 3.5), Quotation::STATUS_ACCEPTED);
            }
        }
        $pr = $this->prAt('2026-10-01', $specs);
        foreach ($users as $user) {
            $this->quote($pr, $user, '2026-10-15', 'USD', 'USD', array_fill(0, $items, 3.8));
        }

        return $pr;
    }

    private function countQueries($pr): int
    {
        $this->actingAs($this->purchasingUser);
        // The rate resolver is request-scoped; reset it so both requests start cold.
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('purchasing.comparison.historical', ['pr_id' => $pr->getRouteKey(), 'view' => 'json']))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
