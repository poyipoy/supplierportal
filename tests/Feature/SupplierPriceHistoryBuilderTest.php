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
            'USD',
        );

        $this->assertSame('monthly', $monthlyChart['type']);
        $this->assertSame('USD', $monthlyChart['currency']);
        $this->assertCount(2, $monthlyRows);
        $this->assertSame([10.0, 12.0], $monthlyRows->pluck('price_per_kg')->all());
        $this->assertSame([10.0, 12.0], $monthlyChart['prices']->all());
        $this->assertSame(20.0, $monthlyRows->last()['change_pct']);
        $this->assertSame(route('supplier.quotations.show', $firstQuotation), $monthlyRows->first()['pr_url']);
        $this->assertNotSame((string) $firstQuotation->id, $monthlyRows->first()['pr_url']);

        [$yearlyChart, $yearlyRows] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'yearly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
            'USD',
        );

        $this->assertSame('yearly', $yearlyChart['type']);
        $this->assertSame('USD', $yearlyChart['currency']);
        $this->assertSame(['2025', '2026'], $yearlyRows->pluck('period')->all());
        $this->assertSame([10.0, 12.0], $yearlyRows->pluck('price_per_kg')->all());
        $this->assertSame([10.0, 12.0], $yearlyChart['prices']->all());
        $this->assertSame(20.0, $yearlyRows->last()['change_pct']);

        $export = new SupplierPriceHistoryExport(
            $this->supplier->id,
            'monthly',
            'Cold Rolled Coil',
            null,
            ['thickness' => 2.5, 'width' => 1000],
            'USD',
        );
        [$lateQuotation] = $this->createQuotedItem($this->supplier, 15, '2026-07-15 09:00:00', 2.5, 1000);

        $exportRows = $export->collection();

        $this->assertCount(3, $exportRows);
        $this->assertSame(15.0, $exportRows->last()[3]);
        $this->assertSame('USD', $exportRows->last()[4]);
        $this->assertSame('25.00%', $exportRows->last()[5]);
        $this->assertSame($secondQuotation->currency, $exportRows->get(1)[4]);
        $this->assertSame($lateQuotation->currency, $exportRows->last()[4]);
        $this->assertSame([
            'No. PR',
            'PO Date',
            'Status',
            'Price/Kg',
            'Currency',
            '% Change',
        ], $export->headings());

        $yearlyExport = new SupplierPriceHistoryExport(
            $this->supplier->id,
            'yearly',
            'Cold Rolled Coil',
            null,
            ['thickness' => 2.5, 'width' => 1000],
            'USD',
        );

        $this->assertSame([
            'Year',
            'Average Price/Kg',
            'Lowest Price/Kg',
            'Highest Price/Kg',
            'Currency',
            '% Change',
        ], $yearlyExport->headings());
        $this->assertSame('USD', $yearlyExport->collection()->first()[4]);
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
            'USD',
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
            'USD',
        );

        $this->assertCount(0, $withoutPo);
    }

    public function test_supplier_history_filters_original_prices_by_currency_and_defaults_to_latest_available_currency(): void
    {
        $idrRate = ExchangeRate::create([
            'currency' => 'IDR',
            'rate_to_idr' => 1,
            'valid_from' => '2025-01-01',
            'created_by' => $this->purchasing->id,
        ]);
        $cnyRate = ExchangeRate::create([
            'currency' => 'CNY',
            'rate_to_idr' => 2200,
            'valid_from' => '2025-01-01',
            'created_by' => $this->purchasing->id,
        ]);

        $this->createQuotedItem($this->supplier, 150000, '2025-08-15 09:00:00', 2.5, 1000, 'IDR', $idrRate);
        $this->createQuotedItem($this->supplier, 10, '2026-02-15 09:00:00', 2.5, 1000, 'USD', $this->rate);
        $this->createQuotedItem($this->otherSupplier, 80, '2026-04-15 09:00:00', 2.5, 1000, 'CNY', $cnyRate);

        $builder = app(SupplierPriceHistoryBuilder::class);

        $this->assertSame(
            ['USD', 'IDR'],
            $builder->availableCurrencies($this->supplier->id, 'Cold Rolled Coil', ['thickness' => 2.5, 'width' => 1000])->all(),
        );
        $this->assertSame('IDR', $builder->resolveCurrency(
            $this->supplier->id,
            'Cold Rolled Coil',
            ['thickness' => 2.5, 'width' => 1000],
            'IDR',
        ));
        $this->assertSame('USD', $builder->resolveCurrency(
            $this->supplier->id,
            'Cold Rolled Coil',
            ['thickness' => 2.5, 'width' => 1000],
            'CNY',
        ));

        [$idrChart, $idrRows] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'monthly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
            'IDR',
        );

        $this->assertSame('IDR', $idrChart['currency']);
        $this->assertSame([150000.0], $idrChart['prices']->all());
        $this->assertSame(['IDR'], $idrRows->pluck('currency')->all());
        $this->assertArrayNotHasKey('price_idr', $idrRows->first());

        [$defaultChart, $defaultRows] = $builder->build(
            $this->supplier->id,
            'Cold Rolled Coil',
            'monthly',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );

        $this->assertSame('USD', $defaultChart['currency']);
        $this->assertSame([10.0], $defaultRows->pluck('price_per_kg')->all());

        $legacyExport = new SupplierPriceHistoryExport(
            $this->supplier->id,
            'monthly',
            'Cold Rolled Coil',
            null,
            ['thickness' => 2.5, 'width' => 1000],
        );
        $this->assertSame('USD', $legacyExport->collection()->first()[4]);

        $this->actingAs($this->supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson(route('supplier.price-history.historical', [
                'material_name' => 'Cold Rolled Coil',
                'currency' => 'IDR',
                'range' => 'all',
                'view' => 'json',
            ]))
            ->assertOk()
            ->assertJsonPath('currency', 'IDR')
            ->assertJsonPath('chartData.currency', 'IDR')
            ->assertJsonPath('chartData.prices.0', 150000)
            ->assertJsonPath('tableData.0.currency', 'IDR')
            ->assertJsonMissingPath('chartData.pricesIdr');

        $response = $this->actingAs($this->supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson(route('supplier.price-history.index', [
                'draw' => 1,
                'start' => 0,
                'length' => 25,
            ]))
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2);

        $overviewRows = collect($response->json('data'));
        $this->assertSame(['IDR', 'USD'], $overviewRows->pluck('currency')->sort()->values()->all());
        $this->assertTrue($overviewRows->every(fn ($row) => ! str_contains($row['price_info'], 'Rp ')));
        $this->assertTrue($overviewRows->every(fn ($row) => str_contains($row['action'], 'currency=')));
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
        string $currency = 'USD',
        ?ExchangeRate $exchangeRate = null,
    ): array {
        $exchangeRate ??= $this->rate;
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
            'exchange_rate_id' => $exchangeRate->id,
            'currency' => $currency,
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
            'currency' => $currency,
            'exchange_rate_id' => $exchangeRate->id,
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
