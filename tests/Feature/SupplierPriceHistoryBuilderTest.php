<?php

namespace Tests\Feature;

use App\Exports\SupplierPriceHistoryExport;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\User;
use App\Support\SupplierPriceHistoryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPriceHistoryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    private Period $period;

    private ExchangeRate $rate;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->period = Period::create([
            'name' => 'Price History Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2025-01-01',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_builder_preserves_monthly_yearly_dimension_and_supplier_scopes_while_export_queries_on_collection(): void
    {
        [$firstQuotation] = $this->createQuotedItem($this->supplier, 10, '2025-08-15 09:00:00', 2.5, 1000);
        [$secondQuotation] = $this->createQuotedItem($this->supplier, 12, '2026-02-15 09:00:00', 2.5, 1000);
        $this->createQuotedItem($this->supplier, 70, '2026-03-15 09:00:00', 3.0, 1000);
        $this->createQuotedItem($this->otherSupplier, 99, '2026-04-15 09:00:00', 2.5, 1000);

        $builder = app(SupplierPriceHistoryBuilder::class);
        [$monthlyChart, $monthlyRows] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'monthly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );

        $this->assertSame('monthly', $monthlyChart['type']);
        $this->assertCount(2, $monthlyRows);
        $this->assertSame([10.0, 12.0], $monthlyRows->pluck('price_per_kg')->all());
        $this->assertSame([160000.0, 192000.0], $monthlyRows->pluck('price_idr')->all());
        $this->assertSame(20.0, $monthlyRows->last()['change_pct']);
        $this->assertSame(route('supplier.quotations.show', $firstQuotation), $monthlyRows->first()['pr_url']);
        $this->assertNotSame((string) $firstQuotation->id, $monthlyRows->first()['pr_url']);

        [$yearlyChart, $yearlyRows] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'yearly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );

        $this->assertSame('yearly', $yearlyChart['type']);
        $this->assertSame(['2025', '2026'], $yearlyRows->pluck('period')->all());
        $this->assertSame([160000.0, 192000.0], $yearlyRows->pluck('price_idr')->all());
        $this->assertSame(20.0, $yearlyRows->last()['change_pct']);

        $export = new SupplierPriceHistoryExport(
            $this->supplier->id,
            'monthly',
            'Cold Rolled Coil',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );
        [$lateQuotation] = $this->createQuotedItem($this->supplier, 15, '2026-07-15 09:00:00', 2.5, 1000);

        $exportRows = $export->collection();

        $this->assertCount(3, $exportRows);
        $this->assertSame(15.0, $exportRows->last()[3]);
        $this->assertSame('USD', $exportRows->last()[4]);
        $this->assertSame(240000.0, $exportRows->last()[5]);
        $this->assertSame('25.00%', $exportRows->last()[6]);
        $this->assertSame($secondQuotation->currency, $exportRows->get(1)[4]);
        $this->assertSame($lateQuotation->currency, $exportRows->last()[4]);
        $this->assertSame([
            'No. PR',
            'PO Date',
            'Status',
            'Price/Kg',
            'Currency',
            'IDR Price',
            '% Change',
        ], $export->headings());
    }

    public function test_history_requires_a_po_and_buckets_on_po_created_at(): void
    {
        [$quotation, $item] = $this->createQuotedItem($this->supplier, 11, '2025-08-15 09:00:00', 2.5, 1000);
        $purchaseOrder = $quotation->purchaseOrders()->firstOrFail();
        $purchaseOrder->forceFill([
            'created_at' => '2026-12-10 14:30:00',
            'updated_at' => '2026-12-10 14:30:00',
        ])->saveQuietly();

        $builder = app(SupplierPriceHistoryBuilder::class);
        [, $rows] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'monthly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Dec 2026', $rows->first()['period']);
        $this->assertSame('10 Dec 2026', $rows->first()['purchase_order_at_display']);
        $this->assertSame($purchaseOrder->id, $rows->first()['purchase_order_id']);
        $this->assertSame($item->id, $quotation->items()->firstOrFail()->pr_item_id);

        $quotation->purchaseOrders()->detach();

        [, $withoutPo] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'monthly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );

        $this->assertCount(0, $withoutPo);
    }

    public function test_vs_best_price_uses_po_backed_history_without_sql_alias_errors(): void
    {
        $this->createQuotedItem($this->supplier, 10, '2025-08-15 09:00:00', 2.5, 1000);
        $this->createQuotedItem($this->supplier, 12, '2026-02-15 09:00:00', 2.5, 1000);

        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson(route('purchasing.comparison.vs-best.data', [
                'date_from' => '2025-01',
                'date_to' => '2026-12',
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]))
            ->assertOk();

        $this->assertGreaterThan(0, $response->json('recordsTotal'));
        $this->assertSame(160000.0, (float) $response->json('data.0.best_price_idr'));
    }

    private function createQuotedItem(
        User $supplier,
        float $price,
        string $submittedAt,
        float $thickness,
        int $width,
    ): array {
        $this->sequence++;
        $requisition = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/PH/'.str_pad((string) $this->sequence, 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
        ]);
        $item = $requisition->items()->create([
            'hs_code' => '7209.16.00',
            'material_name' => 'Cold Rolled Coil',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => $thickness,
            'width' => $width,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $requisition->id,
            'supplier_id' => $supplier->id,
            'exchange_rate_id' => $this->rate->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => $submittedAt,
        ]);
        $quotation->items()->create([
            'pr_item_id' => $item->id,
            'price_per_kg' => $price,
            'amount' => $price * 100,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/PH/'.str_pad((string) $this->sequence, 3, '0', STR_PAD_LEFT),
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
        ]);
        $purchaseOrder->forceFill([
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ])->saveQuietly();
        $purchaseOrder->quotations()->attach($quotation->id);

        return [$quotation, $item];
    }
}
