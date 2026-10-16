<?php

namespace Tests\Feature\Procurement;

use App\Models\AwardReferencePriceSnapshot;
use App\Models\MaterialMaster;
use App\Models\PrItemAward;
use App\Models\PrItemAwardEvent;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Mission 01 schema (migrations 2026_10_14_000002–000007): additive, nullable,
 * and guarded by CHECK constraints that application code cannot bypass.
 */
class ProcurementSchemaTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private const MYSQL_CHECK_VIOLATION = 3819;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_logistics_columns_are_nullable_and_constrained_on_requisitions_and_purchase_orders(): void
    {
        foreach (['purchase_requisitions', 'purchase_orders'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['port_of_discharge', 'incoterm']), $table);
        }

        $legacy = $this->createRequisition();
        $this->assertNull($legacy->fresh()->port_of_discharge);
        $this->assertNull($legacy->fresh()->incoterm);

        foreach (config('procurement.logistics.ports_of_discharge') as $port) {
            foreach (config('procurement.logistics.incoterms') as $incoterm) {
                DB::table('purchase_requisitions')->where('id', $legacy->id)->update(['port_of_discharge' => $port, 'incoterm' => $incoterm]);
                $this->assertSame([$port, $incoterm], array_values((array) DB::table('purchase_requisitions')->where('id', $legacy->id)->first(['port_of_discharge', 'incoterm'])));
            }
        }

        $this->assertCheckViolation(fn () => DB::table('purchase_requisitions')->where('id', $legacy->id)->update(['port_of_discharge' => 'BELAWAN']));
        $this->assertCheckViolation(fn () => DB::table('purchase_requisitions')->where('id', $legacy->id)->update(['incoterm' => 'EXW']));

        $po = $this->generatePurchaseOrder();
        $this->assertNull($po->fresh()->port_of_discharge);
        DB::table('purchase_orders')->where('id', $po->id)->update(['port_of_discharge' => 'TANJUNG_PERAK', 'incoterm' => 'FOB']);
        $this->assertCheckViolation(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['port_of_discharge' => 'tanjung_perak']));
        $this->assertCheckViolation(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['incoterm' => 'DDP']));
    }

    public function test_logistics_are_fillable_on_requisitions_and_purchase_orders(): void
    {
        $this->assertContains('port_of_discharge', (new PurchaseRequisition)->getFillable());
        $this->assertContains('incoterm', (new PurchaseRequisition)->getFillable());
        $this->assertContains('port_of_discharge', (new PurchaseOrder)->getFillable());
        $this->assertContains('incoterm', (new PurchaseOrder)->getFillable());
    }

    public function test_award_ordered_and_acceptance_columns_are_nullable_and_checked(): void
    {
        $award = $this->createAward();
        $fresh = $award->fresh();
        foreach (['ordered_qty', 'ordered_weight_per_unit', 'ordered_total_weight_kg', 'ordered_price_per_kg', 'ordered_amount', 'spec_accepted_by', 'spec_accepted_at', 'spec_acceptance_snapshot', 'spec_acceptance_hash'] as $column) {
            $this->assertTrue(Schema::hasColumn('pr_item_awards', $column), $column);
            $this->assertNull($fresh->getAttribute($column), $column);
        }

        DB::table('pr_item_awards')->where('id', $award->id)->update([
            'ordered_qty' => 1,
            'ordered_weight_per_unit' => '3.0000',
            'ordered_total_weight_kg' => '3.0000',
            'ordered_price_per_kg' => '2.5000',
            'ordered_amount' => '7.5000',
        ]);
        $this->assertSame(1, $award->fresh()->ordered_qty);
        $this->assertSame('7.5000', $award->fresh()->ordered_amount);

        $this->assertCheckViolation(fn () => DB::table('pr_item_awards')->where('id', $award->id)->update(['ordered_qty' => 0]));
        foreach (['ordered_weight_per_unit', 'ordered_total_weight_kg', 'ordered_price_per_kg', 'ordered_amount'] as $column) {
            $this->assertCheckViolation(fn () => DB::table('pr_item_awards')->where('id', $award->id)->update([$column => '-0.0001']), $column);
        }
    }

    public function test_frozen_and_acceptance_fields_are_never_mass_assignable(): void
    {
        $award = new PrItemAward([
            'ordered_qty' => 12,
            'ordered_weight_per_unit' => '3',
            'ordered_total_weight_kg' => '36',
            'ordered_price_per_kg' => '2.5',
            'ordered_amount' => '90',
            'spec_accepted_by' => $this->purchasing->id,
            'spec_accepted_at' => now(),
            'spec_acceptance_snapshot' => ['thickness' => 2],
            'spec_acceptance_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame([], $award->getAttributes());
    }

    public function test_award_events_are_append_only(): void
    {
        $award = $this->createAward();
        $event = PrItemAwardEvent::create([
            'pr_item_award_id' => $award->id,
            'pr_id' => $award->pr_id,
            'pr_item_id' => $award->pr_item_id,
            'quotation_item_id' => $award->quotation_item_id,
            'event' => 'selection_cleared',
            'payload' => ['reason' => 're-pointed'],
            'actor_id' => $this->purchasing->id,
        ]);

        $this->assertNotNull($event->created_at);
        $this->assertSame(['reason' => 're-pointed'], $event->fresh()->payload);
        $this->assertFalse(Schema::hasColumn('pr_item_award_events', 'updated_at'));
        $this->assertSame(1, $award->events()->count());

        $this->assertThrows(fn () => $event->update(['event' => 'tampered']), LogicException::class);
        $this->assertThrows(fn () => $event->delete(), LogicException::class);
        $this->assertSame('selection_cleared', $event->fresh()->event);
    }

    public function test_reference_price_constraints_reject_invalid_rows(): void
    {
        $material = $this->createMaterialMaster();
        $valid = $this->referencePriceRow($material);

        $id = DB::table('material_reference_prices')->insertGetId($valid);
        $this->assertSame('active', DB::table('material_reference_prices')->where('id', $id)->value('status'));
        DB::table('material_reference_prices')->insert(array_merge($valid, ['shape' => 'Round', 'dimension_field' => 'd_outer']));
        DB::table('material_reference_prices')->insert(array_merge($valid, ['shape' => 'Hollow', 'dimension_field' => 'd_outer', 'valid_until' => null]));

        $invalid = [
            'unknown shape' => ['shape' => 'Hexagon'],
            'flat must use thickness' => ['dimension_field' => 'd_outer'],
            'round must use outer diameter' => ['shape' => 'Round', 'dimension_field' => 'thickness'],
            'hollow never uses inner diameter' => ['shape' => 'Hollow', 'dimension_field' => 'd_inner'],
            'non-positive minimum' => ['dimension_min' => '0'],
            'maximum below minimum' => ['dimension_min' => '150', 'dimension_max' => '149.9999'],
            'non-positive price' => ['price_per_kg' => '0'],
            'end before start' => ['valid_until' => '2026-09-30'],
            'unsupported currency' => ['currency' => 'EUR'],
            'unknown status' => ['status' => 'archived'],
            'non-canonical shape case' => ['shape' => 'flat'],
            'non-canonical currency case' => ['currency' => 'idr'],
            'non-canonical status case' => ['status' => 'ACTIVE'],
        ];

        foreach ($invalid as $case => $override) {
            $this->assertCheckViolation(fn () => DB::table('material_reference_prices')->insert(array_merge($valid, $override)), $case);
        }
    }

    public function test_reference_price_master_cannot_be_deleted_while_referenced(): void
    {
        $material = $this->createMaterialMaster();
        DB::table('material_reference_prices')->insert($this->referencePriceRow($material));

        $this->expectException(QueryException::class);
        DB::table('material_masters')->where('id', $material->id)->delete();
    }

    public function test_reference_snapshots_are_one_per_award_coherent_and_immutable(): void
    {
        $po = $this->generatePurchaseOrder();
        $award = $po->awards()->sole();
        // Start from an award without the snapshot PO generation wrote (M06B).
        DB::table('award_reference_price_snapshots')->where('pr_item_award_id', $award->id)->delete();
        $base = [
            'pr_item_award_id' => $award->id,
            'purchase_order_id' => $po->id,
            'evaluation_date' => '2026-10-14',
            'po_currency' => 'USD',
            'po_rate_id' => $this->usdRate->id,
            'created_at' => now(),
        ];

        $this->assertCheckViolation(fn () => DB::table('award_reference_price_snapshots')->insert($base + ['status' => 'matched']), 'matched without reference');
        $this->assertCheckViolation(fn () => DB::table('award_reference_price_snapshots')->insert($base + ['status' => 'not_available']), 'not available without reason');
        $this->assertCheckViolation(fn () => DB::table('award_reference_price_snapshots')->insert($base + ['status' => 'pending', 'reason_code' => 'x']), 'unknown status');

        $snapshot = AwardReferencePriceSnapshot::create(array_merge($base, ['status' => 'not_available', 'reason_code' => 'material_unmapped']));
        $this->assertSame($snapshot->id, $award->referencePriceSnapshot()->value('id'));
        $this->assertFalse(Schema::hasColumn('award_reference_price_snapshots', 'updated_at'));

        try {
            DB::table('award_reference_price_snapshots')->insert($base + ['status' => 'not_available', 'reason_code' => 'material_unmapped']);
            $this->fail('A second snapshot for the same award must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        $this->assertThrows(fn () => $snapshot->update(['reason_code' => 'tampered']), LogicException::class);
        $this->assertThrows(fn () => $snapshot->delete(), LogicException::class);
    }

    private function assertCheckViolation(Closure $write, string $case = ''): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertSame(self::MYSQL_CHECK_VIOLATION, $exception->errorInfo[1] ?? null, $case.': '.$exception->getMessage());

            return;
        }

        $this->fail("Expected a CHECK constraint violation: {$case}");
    }

    private function createAward(): PrItemAward
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);

        return app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);
    }

    private function generatePurchaseOrder(): PurchaseOrder
    {
        $award = $this->createAward();

        return app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing)->sole();
    }

    private function createMaterialMaster(): MaterialMaster
    {
        return MaterialMaster::create([
            'material_code' => 'SCM440',
            'normalized_code' => 'SCM440-'.uniqid(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function referencePriceRow(MaterialMaster $material): array
    {
        return [
            'material_master_id' => $material->id,
            'shape' => 'Flat',
            'dimension_field' => 'thickness',
            'dimension_min' => '100',
            'dimension_max' => '150',
            'currency' => 'IDR',
            'price_per_kg' => '50000',
            'valid_from' => '2026-10-01',
            'valid_until' => '2026-12-31',
            'version_no' => 1,
            'created_by' => $this->purchasing->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
