<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemLevelPoGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private User $supplierC;

    private PrItemAwardService $awardService;

    private PurchaseOrderGenerationService $poService;

    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierC = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->awardService = app(PrItemAwardService::class);
        $this->poService = app(PurchaseOrderGenerationService::class);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_one_pr_one_winning_supplier_creates_one_po(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);

        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items[0]->id,
            $pr->items[1]->id => $qA->items[1]->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(1, $pos);
        $po = $pos->first();

        $this->assertSame($this->supplierA->id, $po->supplier_id);
        $this->assertSame('active', $po->status);
        $this->assertTrue($po->quotations->contains($qA));
        $this->assertCount(2, $po->awards);

        // PR should be fully awarded and marked completed
        $this->assertSame('completed', $pr->fresh()->status);
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qA->fresh()->status);
    }

    public function test_one_pr_two_winning_suppliers_creates_two_separate_pos(): void
    {
        $pr = $this->createRequisition(2);
        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Item 1 -> Supplier A; Item 2 -> Supplier B
        $awards = $this->awardService->awardBatch($pr, [
            $item1->id => $qA->items->firstWhere('pr_item_id', $item1->id)->id,
            $item2->id => $qB->items->firstWhere('pr_item_id', $item2->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(2, $pos);

        $poA = $pos->firstWhere('supplier_id', $this->supplierA->id);
        $poB = $pos->firstWhere('supplier_id', $this->supplierB->id);

        $this->assertNotNull($poA);
        $this->assertNotNull($poB);
        $this->assertNotSame($poA->id, $poB->id);

        // Invariant: One PO = Exactly One Supplier
        $this->assertSame($this->supplierA->id, $poA->supplier_id);
        $this->assertSame($this->supplierB->id, $poB->supplier_id);

        // Traceability
        $this->assertCount(1, $poA->awards);
        $this->assertSame($item1->id, $poA->awards->first()->pr_item_id);

        $this->assertCount(1, $poB->awards);
        $this->assertSame($item2->id, $poB->awards->first()->pr_item_id);

        // Both participating quotations become accepted
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qA->fresh()->status);
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qB->fresh()->status);

        // PR fully awarded
        $this->assertSame('completed', $pr->fresh()->status);
    }

    public function test_one_pr_three_winning_suppliers_creates_three_pos(): void
    {
        $pr = $this->createRequisition(3);

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);
        $qC = $this->createSubmittedQuotation($pr, $this->supplierC, 1.9);

        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items->firstWhere('pr_item_id', $pr->items[0]->id)->id,
            $pr->items[1]->id => $qB->items->firstWhere('pr_item_id', $pr->items[1]->id)->id,
            $pr->items[2]->id => $qC->items->firstWhere('pr_item_id', $pr->items[2]->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(3, $pos);
        $this->assertSame([$this->supplierA->id, $this->supplierB->id, $this->supplierC->id], $pos->pluck('supplier_id')->sort()->values()->all());
    }

    public function test_same_supplier_winning_multiple_items_is_grouped_into_one_po(): void
    {
        $pr = $this->createRequisition(3);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Supplier A wins item 0 and item 2; Supplier B wins item 1
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items->firstWhere('pr_item_id', $pr->items[0]->id)->id,
            $pr->items[1]->id => $qB->items->firstWhere('pr_item_id', $pr->items[1]->id)->id,
            $pr->items[2]->id => $qA->items->firstWhere('pr_item_id', $pr->items[2]->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(2, $pos);
        $poA = $pos->firstWhere('supplier_id', $this->supplierA->id);
        $this->assertCount(2, $poA->awards);
    }

    public function test_partial_award_leaves_pr_in_bidding_and_does_not_reject_competing_quotations(): void
    {
        $pr = $this->createRequisition(3);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Only 2 of 3 items awarded
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items->firstWhere('pr_item_id', $pr->items[0]->id)->id,
            $pr->items[1]->id => $qA->items->firstWhere('pr_item_id', $pr->items[1]->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(1, $pos);

        // PR must NOT be completed because item 2 is unresolved
        $this->assertSame('bidding', $pr->fresh()->status);

        // Supplier B's quotation must NOT be rejected because item 2 is still open!
        $this->assertSame(Quotation::STATUS_SUBMITTED, $qB->fresh()->status);
    }

    public function test_full_award_rejects_quotations_with_zero_winning_items(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 2.5); // Lost all items

        // Supplier A wins both items
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items[0]->id,
            $pr->items[1]->id => $qA->items[1]->id,
        ], $this->purchasing);

        $this->poService->generateFromAwards($awards, $this->purchasing);

        // PR completed
        $this->assertSame('completed', $pr->fresh()->status);
        // Supplier A quotation accepted
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qA->fresh()->status);
        // Supplier B quotation rejected
        $this->assertSame(Quotation::STATUS_REJECTED, $qB->fresh()->status);
    }

    public function test_same_supplier_multi_pr_consolidation(): void
    {
        $pr1 = $this->createRequisition(1);
        $pr2 = $this->createRequisition(1);

        $qA1 = $this->createSubmittedQuotation($pr1, $this->supplierA, 2.0);
        $qA2 = $this->createSubmittedQuotation($pr2, $this->supplierA, 2.2);

        $award1 = $this->awardService->awardItem($pr1->items[0], $qA1->items[0], $this->purchasing);
        $award2 = $this->awardService->awardItem($pr2->items[0], $qA2->items[0], $this->purchasing);

        // Consolidate both PR awards into 1 PO
        $pos = $this->poService->generateFromAwards(collect([$award1, $award2]), $this->purchasing);

        $this->assertCount(1, $pos);
        $po = $pos->first();

        $this->assertSame($this->supplierA->id, $po->supplier_id);
        $this->assertCount(2, $po->quotations);
        $this->assertCount(2, $po->awards);

        $this->assertSame('completed', $pr1->fresh()->status);
        $this->assertSame('completed', $pr2->fresh()->status);
    }

    public function test_every_po_commercial_output_contains_only_its_awarded_lines(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 3.0);
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items[0]->id,
            $pr->items[1]->id => $qB->items[1]->id,
        ], $this->purchasing);
        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        foreach ($pos as $po) {
            $item = $po->awards()->firstOrFail()->quotationItem;
            $other = $pr->items->first(fn ($prItem) => $prItem->id !== $item->pr_item_id);
            $this->assertCommercialOutputs($po, [$item->prItem->material_name], [$other->material_name], $item->resolved_amount);
            $this->assertCount(2, $po->quotations->first()->items);
        }
    }

    public function test_legacy_po_commercial_outputs_keep_all_quotation_lines(): void
    {
        $pr = $this->createRequisition(2);
        $quotation = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
        $po->quotations()->attach($quotation->id);
        $this->assertDatabaseCount('pr_item_awards', 0);
        $this->assertCommercialOutputs($po, $pr->items->pluck('material_name')->all(), [], 80.0);
    }

    private function assertCommercialOutputs(PurchaseOrder $po, array $included, array $excluded, float $amount): void
    {
        $assertHtml = function (string $html) use ($included, $excluded): void {
            foreach ($included as $name) {
                $this->assertStringContainsString($name, $html);
            }
            foreach ($excluded as $name) {
                $this->assertStringNotContainsString($name, $html);
            }
        };
        $assertHtml($this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.show', $po))->assertOk()->getContent());
        $assertHtml($this->actingAs($po->supplier)->get(route('supplier.purchase-orders.show', $po))->assertOk()->getContent());

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) use ($assertHtml) {
            $this->assertSame('pdf.po-pdf', $view);
            $assertHtml(view($view, $data)->render());

            return true;
        })->andReturn($pdf);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        $pdf->shouldReceive('download')->once()->andReturn(response('PDF verified'));
        $this->actingAs($this->purchasing)->get(route('shared.pdf.purchase-order', $po))->assertOk();

        $projected = PurchaseOrder::withResolvedTotalIdr()->findOrFail($po->id);
        $this->assertEquals($amount * 16000, (float) $projected->resolved_total_idr);
        $list = (new PurchaseOrdersExport)->map($po->fresh());
        $this->assertEquals($amount, $list[5]);
        $this->assertEquals($amount * 16000, $list[6]);
        $assertHtml($list[3]);
        $export = new PurchaseOrderDetailExport($po->id);
        $rows = $export->collection();
        $this->assertCount(count($included), $rows);
        $this->assertSame(count($included), $export->progressTotalRows());
        $this->assertEquals($amount, $rows->sum(fn ($row) => $row[10]));
        $assertHtml($rows->pluck(4)->implode(', '));
    }

    public function test_purchasing_can_consolidate_multi_pr_awards_through_reachable_http_action(): void
    {
        $pr1 = $this->createRequisition(2);
        $pr2 = $this->createRequisition(2);
        $q1 = $this->createSubmittedQuotation($pr1, $this->supplierA, 2.0);
        $q2 = $this->createSubmittedQuotation($pr2, $this->supplierA, 2.5);
        $a1 = $this->awardService->awardItem($pr1->items[0], $q1->items[0], $this->purchasing);
        $a2 = $this->awardService->awardItem($pr2->items[0], $q2->items[0], $this->purchasing);
        $this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.index'))
            ->assertOk()->assertSee(route('purchasing.purchase-orders.consolidate-awards'), false);
        $this->get(route('purchasing.purchase-orders.consolidate-awards'))
            ->assertOk()->assertSee($pr1->pr_number)->assertSee($pr2->pr_number);
        $this->post(route('purchasing.purchase-orders.consolidate-awards.store'), [
            'award_ids' => [$a1->id, $a2->id], 'estimated_arrival' => now()->addDays(14)->toDateString(),
        ])->assertRedirect()->assertSessionHas('success');
        $po = PurchaseOrder::sole();
        $this->assertSame($this->supplierA->id, $po->supplier_id);
        $this->assertSame($po->id, $a1->fresh()->purchase_order_id);
        $this->assertSame($po->id, $a2->fresh()->purchase_order_id);
        $this->assertEqualsCanonicalizing([$pr1->id, $pr2->id], $po->purchaseRequisitions()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$q1->items[0]->id, $q2->items[0]->id], $po->commercialQuotationItems()->pluck('id')->all());
        $this->assertDatabaseCount('po_quotations', 2);
    }

    public function test_consolidation_rejects_supplier_role_and_mixed_suppliers_without_writes(): void
    {
        $pr1 = $this->createRequisition(1);
        $pr2 = $this->createRequisition(1);
        $q1 = $this->createSubmittedQuotation($pr1, $this->supplierA, 2.0);
        $q2 = $this->createSubmittedQuotation($pr2, $this->supplierB, 2.0);
        $a1 = $this->awardService->awardItem($pr1->items[0], $q1->items[0], $this->purchasing);
        $a2 = $this->awardService->awardItem($pr2->items[0], $q2->items[0], $this->purchasing);
        $payload = ['award_ids' => [$a1->id, $a2->id], 'estimated_arrival' => now()->addDays(14)->toDateString()];
        $this->actingAs($this->supplierA)->get(route('purchasing.purchase-orders.consolidate-awards'))->assertForbidden();
        $this->post(route('purchasing.purchase-orders.consolidate-awards.store'), $payload)->assertForbidden();
        $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.consolidate-awards.store'), $payload)
            ->assertRedirect()->assertSessionHasErrors('award_ids');
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('po_quotations', 0);
        $this->assertNull($a1->fresh()->purchase_order_id);
        $this->assertNull($a2->fresh()->purchase_order_id);
    }

    public function test_consolidation_rejects_currency_stale_and_already_assigned_awards(): void
    {
        $pr1 = $this->createRequisition(1);
        $pr2 = $this->createRequisition(1);
        $q1 = $this->createSubmittedQuotation($pr1, $this->supplierA, 2.0);
        $q2 = $this->createSubmittedQuotation($pr2, $this->supplierA, 2.0);
        $a1 = $this->awardService->awardItem($pr1->items[0], $q1->items[0], $this->purchasing);
        $a2 = $this->awardService->awardItem($pr2->items[0], $q2->items[0], $this->purchasing);
        $payload = ['award_ids' => [$a1->id, $a2->id], 'estimated_arrival' => now()->addDays(14)->toDateString()];
        $q2->update(['currency' => 'JPY']);
        $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.consolidate-awards.store'), $payload)
            ->assertRedirect()->assertSessionHasErrors('award_ids');
        $this->assertDatabaseCount('purchase_orders', 0);
        $q2->update(['currency' => 'USD', 'status' => 'revision_requested']);
        $this->post(route('purchasing.purchase-orders.consolidate-awards.store'), $payload)
            ->assertRedirect()->assertSessionHasErrors('award_ids');
        $this->assertDatabaseCount('purchase_orders', 0);
        $q2->update(['status' => 'submitted']);
        $po = $this->poService->generateFromAwards([$a1->id], $this->purchasing)->first();
        $this->post(route('purchasing.purchase-orders.consolidate-awards.store'), $payload)
            ->assertRedirect()->assertSessionHasErrors('award_ids');
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('po_quotations', 1);
        $this->assertSame($po->id, $a1->fresh()->purchase_order_id);
        $this->assertNull($a2->fresh()->purchase_order_id);
    }

    public function test_single_batch_and_http_finalization_lock_parents_before_children(): void
    {
        $pr = $this->createRequisition(1);
        $quotation = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $queries[] = strtolower($query->sql);
            }
        });
        $operations = [
            fn () => $this->awardService->awardItem($pr->items[0], $quotation->items[0], $this->purchasing),
            fn () => $this->awardService->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing),
            fn () => $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->hash, 'awards' => [$pr->items[0]->id => $quotation->items[0]->id], 'action' => 'generate_pos',
            ])->assertRedirect()->assertSessionHas('success'),
        ];
        foreach ($operations as $operation) {
            $queries = [];
            $operation();
            $previous = -1;
            foreach (['purchase_requisitions', 'pr_items', 'quotations', 'quotation_items', 'pr_item_awards'] as $table) {
                $index = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `'.$table.'`'));
                $this->assertNotFalse($index, 'Missing lock for '.$table);
                $this->assertGreaterThan($previous, $index, 'Unexpected lock order for '.$table);
                $previous = $index;
            }
        }
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertSame(PurchaseOrder::sole()->id, PrItemAward::sole()->purchase_order_id);
    }

    private function createRequisition(int $itemCount = 2): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'PO Gen Period '.rand(100, 9999),
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/'.str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'notes' => 'PO Gen requisition',
        ]);

        for ($i = 1; $i <= $itemCount; $i++) {
            PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => "Material {$i}",
                'quantity' => 2,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 1.5,
                'width' => 100,
                'length' => 200,
                'weight_needed' => 10,
            ]);
        }

        return $pr->fresh(['items']);
    }

    private function createSubmittedQuotation(PurchaseRequisition $pr, User $supplier, float $price): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        foreach ($pr->items as $item) {
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'is_available' => true,
                'price_per_kg' => $price,
                'amount' => $price * $item->total_weight,
            ]);
        }

        return $quotation->fresh(['items']);
    }
}
