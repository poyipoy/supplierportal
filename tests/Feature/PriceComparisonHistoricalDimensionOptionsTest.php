<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gate 2 PUR-07 (§3-G): historical materials are identified by (material, shape) and carry the
 * key-dimension options taken from eligible history only, scoped by supplier, in one query.
 */
class PriceComparisonHistoricalDimensionOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $s1;

    private User $s2;

    private Period $period;

    private ExchangeRate $rate;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-28 10:00:00');
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->period = Period::create(['name' => 'Oct', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]);
        foreach (['s1' => 'PT Satu', 's2' => 'PT Dua'] as $key => $company) {
            $this->{$key} = User::factory()->create(['role' => 'supplier']);
            Supplier::create(['user_id' => $this->{$key}->id, 'company_name' => $company]);
        }

        // S1: an awarded PO whose award is the Flat 10 line; the Flat 77 line on the same PO is unawarded.
        $this->po($this->s1, 'completed', [['SCM440', 'Flat', ['thickness' => 10]], ['SCM440', 'Flat', ['thickness' => 77]]], awardFirst: true);
        $this->po($this->s1, 'completed', [['SCM440', 'Flat', ['thickness' => 12]]]);
        $this->po($this->s1, 'completed', [['SCM440', 'Round', ['d_outer' => 50]], ['SCM440', 'Round', ['d_outer' => 50], ['available_d_outer' => 52]]]);
        $this->po($this->s1, 'cancelled', [['SCM440', 'Flat', ['thickness' => 99]]]);
        $this->po($this->s1, 'completed', [['S45C', 'Hollow', ['d_outer' => 60, 'd_inner' => 30]]]);
        $this->po($this->s1, 'completed', [['NOSHAPE', null, []]]);
        // S2.
        $this->po($this->s2, 'completed', [['SCM440', 'Flat', ['thickness' => 14]]]);
    }

    public function test_options_are_per_material_shape_and_supplier_from_eligible_history_only(): void
    {
        $this->actingAs($this->purchasing);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $s1 = collect($this->getJson(route('purchasing.comparison.historical.materials', ['supplier_id' => $this->s1->getRouteKey()]))->assertOk()->json('materials'));
        $historyQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'po_quotations'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $historyQueries, 'Materials and their options come from one query.');

        $options = fn ($materials, string $name, ?string $shape) => $materials->first(fn ($m) => $m['name'] === $name && $m['shape'] === $shape)['key_options'] ?? 'missing';
        $this->assertSame(['10.0000', '12.0000'], $options($s1, 'SCM440', 'Flat'));
        $this->assertSame(['50.0000', '52.0000'], $options($s1, 'SCM440', 'Round'), 'The offered 52 replaces the requested 50 on that line.');
        $this->assertSame(['60.0000'], $options($s1, 'S45C', 'Hollow'));
        $this->assertSame([], $options($s1, 'NOSHAPE', null));
        $this->assertSame('SCM440 (Flat)', $s1->first(fn ($m) => $m['name'] === 'SCM440' && $m['shape'] === 'Flat')['label']);
        $this->assertSame('S45C', $s1->firstWhere('name', 'S45C')['label']);
        $this->assertArrayNotHasKey('master_id', $s1->first());

        $all = collect($this->getJson(route('purchasing.comparison.historical.materials'))->assertOk()->json('materials'));
        $this->assertSame(['10.0000', '12.0000', '14.0000'], $options($all, 'SCM440', 'Flat'));
        $everyOption = $all->pluck('key_options')->flatten()->all();
        $this->assertNotContains('99.0000', $everyOption, 'Cancelled PO lines never offer a dimension.');
        $this->assertNotContains('77.0000', $everyOption, 'Unawarded lines of an awarded PO never offer a dimension.');
    }

    public function test_history_resolves_material_by_shape_and_filters_by_the_chosen_dimension(): void
    {
        $this->actingAs($this->purchasing);
        $round = $this->getJson(route('purchasing.comparison.historical', ['view' => 'json', 'material_name' => 'SCM440', 'shape' => 'Round']))->assertOk();
        $this->assertSame('Round', $round->json('shape'));
        $this->assertSame(['50.0000', '52.0000'], $round->json('keyOptions'));
        $this->assertSame('d_outer', $round->json('keyDimension.field'));

        $flat12 = $this->getJson(route('purchasing.comparison.historical', ['view' => 'json', 'material_name' => 'SCM440', 'shape' => 'Flat', 'key_min' => '12', 'key_max' => '12']))->assertOk();
        $this->assertSame('Flat', $flat12->json('shape'));
        $this->assertSame('12.0000', $flat12->json('keyDimension.min'));
        $this->assertCount(1, $flat12->json('tableData'), 'Only the thickness 12 line remains.');

        $noShape = $this->getJson(route('purchasing.comparison.historical', ['view' => 'json', 'material_name' => 'NOSHAPE']))->assertOk();
        $this->assertNull($noShape->json('shape'));
        $this->assertNull($noShape->json('keyDimension.field'), 'A material without a shape has no key dimension.');
        $this->assertCount(1, $noShape->json('tableData'));

        // A shape that is not on file for the name falls back to that name's first shape.
        $fallback = $this->getJson(route('purchasing.comparison.historical', ['view' => 'json', 'material_name' => 'S45C', 'shape' => 'Flat']))->assertOk();
        $this->assertSame('Hollow', $fallback->json('shape'));
    }

    /** @param list<array{0: string, 1: ?string, 2: array, 3?: array}> $lines */
    private function po(User $supplier, string $status, array $lines, bool $awardFirst = false): void
    {
        $this->sequence++;
        $pr = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/OPT/'.$this->sequence, 'status' => 'completed']);
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-03-01 09:00:00']);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'po_number' => 'PO/OPT/'.$this->sequence, 'status' => $status, 'created_by' => $this->purchasing->id]);
        $po->quotations()->attach($quotation->id);
        foreach ($lines as $index => $line) {
            $item = $pr->items()->create(['material_name' => $line[0], 'shape' => $line[1], 'quantity' => 2, 'length' => 200, 'weight_needed' => 5, ...$line[2]]);
            $quotationItem = $quotation->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => 10, 'amount' => 100, ...($line[3] ?? [])]);
            if ($awardFirst && $index === 0) {
                PrItemAward::create(['pr_id' => $pr->id, 'pr_item_id' => $item->id, 'quotation_id' => $quotation->id, 'quotation_item_id' => $quotationItem->id, 'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'awarded_by' => $this->purchasing->id, 'awarded_at' => now()]);
            }
        }
    }
}
