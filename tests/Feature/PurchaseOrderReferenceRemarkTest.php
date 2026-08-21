<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrdersExport;
use App\Exports\RequisitionsExport;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderReferenceRemarkTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'name' => 'Purchasing Mission One',
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $this->supplierA = User::factory()->create([
            'name' => 'Supplier Alpha',
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->supplierB = User::factory()->create([
            'name' => 'Supplier Beta',
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->period = Period::create([
            'name' => 'August Mission One',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_pr_reference_accessor_is_unique_and_has_a_fallback(): void
    {
        $prA = new PurchaseRequisition(['pr_number' => 'REQ/08/2026/001']);
        $prA->setAttribute('id', 1);
        $prB = new PurchaseRequisition(['pr_number' => 'REQ/08/2026/002']);
        $prB->setAttribute('id', 2);

        $quotationA = new Quotation;
        $quotationA->setRelation('purchaseRequisition', $prA);
        $quotationADuplicate = new Quotation;
        $quotationADuplicate->setRelation('purchaseRequisition', $prA);
        $quotationB = new Quotation;
        $quotationB->setRelation('purchaseRequisition', $prB);

        $po = new PurchaseOrder;
        $po->setRelation('quotations', new EloquentCollection([$quotationA, $quotationADuplicate, $quotationB]));

        $this->assertSame('REQ/08/2026/001, REQ/08/2026/002', $po->pr_reference);

        $po->setRelation('quotations', new EloquentCollection);
        $this->assertSame('-', $po->pr_reference);
    }

    public function test_purchase_order_datatables_search_columns_escape_output_and_preserve_supplier_scope(): void
    {
        $prA = $this->createRequisition('REQ/08/2026/011', 'Material Alpha');
        $prA2 = $this->createRequisition('REQ/08/2026/012', 'Material Alpha Two');
        $prB = $this->createRequisition('REQ/08/2026/099', 'Material Beta');

        $longUnsafeNotes = '<script>alert(1)</script> '.str_repeat('long remark ', 6);
        $poA = $this->createPurchaseOrder($this->supplierA, [$prA, $prA2], 'PO/08/2026/011', $longUnsafeNotes, 'active');
        $this->createPurchaseOrder($this->supplierB, [$prB], 'PO/08/2026/099', 'Supplier Beta private note', 'completed');

        Model::preventLazyLoading(true);

        try {
            $allResponse = $this->dataTableRequest(
                $this->purchasing,
                'purchasing.purchase-orders.index',
                $this->purchasingColumns()
            );
        } finally {
            Model::preventLazyLoading(false);
        }

        $allResponse->assertOk()->assertJsonPath('recordsFiltered', 2);
        $row = collect($allResponse->json('data'))->firstWhere('po_number_display', $poA->po_number);

        $this->assertNotNull($row);
        $this->assertSame('REQ/08/2026/011, REQ/08/2026/012', $row['pr_reference']);
        $this->assertStringContainsString('&lt;script&gt;', $row['remark_display']);
        $this->assertStringNotContainsString('<script>', $row['remark_display']);
        $this->assertStringContainsString('title=', $row['remark_display']);

        $this->dataTableRequest(
            $this->purchasing,
            'purchasing.purchase-orders.index',
            $this->purchasingColumns(),
            'REQ/08/2026/012'
        )->assertOk()->assertJsonPath('recordsFiltered', 1);

        $this->dataTableRequest(
            $this->purchasing,
            'purchasing.purchase-orders.index',
            $this->purchasingColumns(),
            'long remark'
        )->assertOk()->assertJsonPath('recordsFiltered', 1);

        $this->dataTableRequest(
            $this->purchasing,
            'purchasing.purchase-orders.index',
            $this->purchasingColumns(),
            'Supplier Alpha'
        )->assertOk()->assertJsonPath('recordsFiltered', 1);

        $this->dataTableRequest(
            $this->purchasing,
            'purchasing.purchase-orders.index',
            $this->purchasingColumns(),
            '',
            ['status' => 'active']
        )->assertOk()->assertJsonPath('recordsFiltered', 1);

        $supplierResponse = $this->dataTableRequest(
            $this->supplierA,
            'supplier.purchase-orders.index',
            $this->supplierColumns()
        );
        $supplierResponse->assertOk()->assertJsonPath('recordsFiltered', 1);
        $this->assertSame($poA->po_number, $supplierResponse->json('data.0.po_number_display'));

        $this->dataTableRequest(
            $this->supplierA,
            'supplier.purchase-orders.index',
            $this->supplierColumns(),
            'REQ/08/2026/099'
        )->assertOk()->assertJsonPath('recordsFiltered', 0)->assertJsonCount(0, 'data');
    }

    public function test_po_details_and_exports_include_references_and_formula_safe_remarks(): void
    {
        $this->supplierA->update(['name' => '+Formula Supplier']);
        $pr = $this->createRequisition('REQ/08/2026/021', '@Formula Material', '=PR item remark');
        $po = $this->createPurchaseOrder($this->supplierA, [$pr], 'PO/08/2026/021', '-PO formula remark', 'active');

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSeeText('Reference (No. PR)')
            ->assertSeeText('REQ/08/2026/021')
            ->assertSeeText('-PO formula remark')
            ->assertSee('<th scope="col">Reference (No. PR)</th>', false)
            ->assertSee('<th scope="col">Remark</th>', false)
            ->assertSee('title="-PO formula remark"', false)
            ->assertSee(route('purchasing.requisitions.show', $pr), false);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.purchase-orders.show', $po))
            ->assertOk()
            ->assertSeeText('Reference (No. PR)')
            ->assertSeeText('REQ/08/2026/021')
            ->assertSeeText('-PO formula remark')
            ->assertSee('<th scope="col">Reference (No. PR)</th>', false)
            ->assertSee('<th scope="col">Remark</th>', false)
            ->assertSee('title="-PO formula remark"', false)
            ->assertSee(route('supplier.quotations.show', $po->quotations()->firstOrFail()), false);

        $requisitionExport = new RequisitionsExport($this->period->id);
        $requisitionRow = $requisitionExport->collection()->first();

        $this->assertSame('PR Total KG', $requisitionExport->headings()[7]);
        $this->assertSame("'@Formula Material", $requisitionRow[2]);
        $this->assertSame(200.0, $requisitionRow[7]);
        $this->assertSame("'=PR item remark", $requisitionRow[8]);

        $poExport = new PurchaseOrdersExport($this->supplierA->id);
        $poRow = $poExport->collection()->first();

        $this->assertSame('Remark', $poExport->headings()[8]);
        $this->assertSame('REQ/08/2026/021', $poRow[1]);
        $this->assertSame("'+Formula Supplier", $poRow[2]);
        $this->assertSame("'@Formula Material", $poRow[3]);
        $this->assertSame('USD', $poRow[4]);
        $this->assertIsFloat($poRow[5]);
        $this->assertIsFloat($poRow[6]);
        $this->assertSame("'-PO formula remark", $poRow[8]);
    }

    private function createRequisition(string $number, string $materialName, ?string $remark = null): PurchaseRequisition
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => $number,
            'status' => 'completed',
        ]);

        $pr->items()->create([
            'hs_code' => '7209.16.00',
            'material_name' => $materialName,
            'quantity' => 2,
            'shape' => 'Flat',
            'thickness' => 2.5,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
            'remark' => $remark,
        ]);

        return $pr;
    }

    /**
     * @param  array<int, PurchaseRequisition>  $requisitions
     */
    private function createPurchaseOrder(
        User $supplier,
        array $requisitions,
        string $poNumber,
        string $notes,
        string $status
    ): PurchaseOrder {
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now(),
            'created_by' => $this->purchasing->id,
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => $poNumber,
            'status' => $status,
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(30),
            'notes' => $notes,
        ]);

        foreach ($requisitions as $requisition) {
            $quotation = Quotation::create([
                'pr_id' => $requisition->id,
                'supplier_id' => $supplier->id,
                'exchange_rate_id' => $rate->id,
                'currency' => 'USD',
                'status' => 'accepted',
                'submitted_at' => now(),
            ]);
            $prItem = $requisition->items()->firstOrFail();
            $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'price_per_kg' => 2.5,
                'amount' => 2.5 * $prItem->total_weight,
            ]);
            $po->quotations()->attach($quotation->id);
        }

        return $po;
    }

    private function dataTableRequest(
        User $user,
        string $routeName,
        array $columns,
        string $search = '',
        array $extra = []
    ) {
        $parameters = $extra + [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'columns' => $columns,
            'order' => [],
            'search' => ['value' => $search, 'regex' => false],
        ];

        return $this->actingAs($user)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route($routeName).'?'.http_build_query($parameters));
    }

    private function purchasingColumns(): array
    {
        return $this->dataTableColumns([
            ['po_number_display', 'po_number', true],
            ['supplier_name', 'supplier_name', true],
            ['period_name', 'period_name', true],
            ['pr_reference', 'pr_reference', true],
            ['remark_display', 'remark_display', true],
            ['total_idr', 'total_idr', false],
            ['status_badge', 'status', true],
            ['estimated_date', 'estimated_arrival', true],
            ['action', 'action', false],
        ]);
    }

    private function supplierColumns(): array
    {
        return $this->dataTableColumns([
            ['po_number_display', 'po_number', true],
            ['period_name', 'period_name', true],
            ['pr_reference', 'pr_reference', true],
            ['remark_display', 'remark_display', true],
            ['total_idr', 'total_idr', false],
            ['status_badge', 'status', true],
            ['estimated_date', 'estimated_arrival', true],
            ['action', 'action', false],
        ]);
    }

    private function dataTableColumns(array $definitions): array
    {
        return array_map(fn (array $definition) => [
            'data' => $definition[0],
            'name' => $definition[1],
            'searchable' => $definition[2] ? 'true' : 'false',
            'orderable' => 'false',
            'search' => ['value' => '', 'regex' => 'false'],
        ], $definitions);
    }
}
