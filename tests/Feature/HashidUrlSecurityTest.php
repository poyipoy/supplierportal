<?php

namespace Tests\Feature;

use App\Http\Middleware\DecodeHashids;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Support\PurchasingNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HashidUrlSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    private User $qc;

    private Period $period;

    private ExchangeRate $rate;

    private PurchaseRequisition $requisition;

    private PrItem $item;

    private Quotation $quotation;

    private Quotation $otherQuotation;

    private PurchaseOrder $purchaseOrder;

    private QcInspection $inspection;

    private MaterialClaim $claim;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerImplicitBindingRoutes();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $this->period = Period::create([
            'name' => 'Hashid Test Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now(),
            'created_by' => $this->admin->id,
        ]);
        $this->requisition = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/08/2026/901',
            'notes' => 'Hashid URL fixture',
            'status' => 'submitted',
        ]);
        $this->item = PrItem::create([
            'pr_id' => $this->requisition->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Hashid Test Material',
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $this->quotation = $this->createQuotation($this->supplier, 'submitted', 1.5);
        $this->otherQuotation = $this->createQuotation($this->otherSupplier, 'submitted', 1.7);

        $this->purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/08/2026/901',
            'status' => 'claim_needed',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addWeek(),
        ]);
        $this->purchaseOrder->quotations()->attach($this->quotation->id);
        $this->inspection = QcInspection::create([
            'po_id' => $this->purchaseOrder->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);
        $this->claim = MaterialClaim::create([
            'inspection_id' => $this->inspection->id,
            'po_id' => $this->purchaseOrder->id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending',
            'description' => 'Hashid URL claim',
            'deadline' => now()->addWeek(),
        ]);
        $this->conversation = Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $this->requisition->id,
            'purchasing_user_id' => $this->purchasing->id,
            'supplier_user_id' => $this->supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    public function test_implicit_bindings_accept_only_canonical_hashes_for_all_models(): void
    {
        $models = [
            'purchase-requisitions' => $this->requisition,
            'quotations' => $this->quotation,
            'purchase-orders' => $this->purchaseOrder,
            'material-claims' => $this->claim,
            'qc-inspections' => $this->inspection,
            'conversations' => $this->conversation,
            'users' => $this->supplier,
        ];

        foreach ($models as $path => $model) {
            $this->assertTrue($model->is($model->resolveRouteBinding($model->getRouteKey(), 'id')));
            $this->assertNull($model->resolveRouteBinding((string) $model->id, 'id'));
            $this->get("/_hashid-test/{$path}/{$model->getRouteKey()}")
                ->assertOk()
                ->assertJsonPath('id', $model->id);
            $this->get("/_hashid-test/{$path}/{$model->id}")->assertNotFound();
            $this->get("/_hashid-test/{$path}/invalid-hash")->assertNotFound();
        }
    }

    public function test_manual_scalar_routes_accept_hashes_and_reject_plain_or_invalid_ids(): void
    {
        $routes = [
            [$this->admin, 'admin.requisitions.show', $this->requisition],
            [$this->purchasing, 'purchasing.quotations.show', $this->quotation],
            [$this->purchasing, 'purchasing.purchase-orders.show', $this->purchaseOrder],
            [$this->purchasing, 'purchasing.claims.show', $this->claim],
            [$this->qc, 'qc.inspections.show', $this->inspection],
            [$this->purchasing, 'purchasing.conversations.show', $this->conversation],
        ];

        foreach ($routes as [$user, $name, $model]) {
            $this->actingAs($user)->get(route($name, $model))->assertOk();
            $this->actingAs($user)->get(route($name, $model->id))->assertNotFound();
            $this->actingAs($user)->get(route($name, 'invalid-hash'))->assertNotFound();
        }

        foreach ([
            ['shared.pdf.purchase-order', $this->purchaseOrder],
            ['shared.pdf.qc-inspection', $this->inspection],
        ] as [$name, $model]) {
            $this->actingAs($this->purchasing)->get(route($name, $model))->assertOk();
            $this->actingAs($this->purchasing)->get(route($name, $model->id))->assertNotFound();
            $this->actingAs($this->purchasing)->get(route($name, 'invalid-hash'))->assertNotFound();
        }

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.period', $this->period->id))
            ->assertOk();

        $validParameters = ['pr_id' => $this->requisition, 'supplier_id' => $this->supplier];
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.conversations.start.pr', $validParameters))
            ->assertOk()
            ->assertJsonPath('conversation_id', $this->conversation->getRouteKey());

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.conversations.start.pr', [
                'pr_id' => $this->requisition,
                'supplier_id' => $this->supplier->id,
            ]))
            ->assertNotFound();

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.conversations.start.pr', [
                'pr_id' => $this->requisition,
                'supplier_id' => 'invalid-hash',
            ]))
            ->assertNotFound();
    }

    public function test_hashed_query_filters_are_resolved_locally_and_raw_values_are_rejected(): void
    {
        $purchasingCases = [
            ['purchasing.quotations.index', ['supplier_id' => $this->supplier]],
            ['purchasing.purchase-orders.index', ['supplier_id' => $this->supplier]],
            ['purchasing.comparison.inter-supplier', ['pr_id' => $this->requisition]],
            ['purchasing.comparison.historical', ['supplier_id' => $this->supplier]],
            ['purchasing.comparison.historical', ['supplier' => $this->supplier]],
        ];

        foreach ($purchasingCases as [$name, $parameters]) {
            $key = array_key_first($parameters);
            $model = $parameters[$key];

            $this->actingAs($this->purchasing)->get(route($name, $parameters))->assertOk();
            $this->actingAs($this->purchasing)->get(route($name, [$key => $model->id]))->assertNotFound();
            $this->actingAs($this->purchasing)->get(route($name, [$key => 'invalid-hash']))->assertNotFound();
        }

        $this->actingAs($this->admin)
            ->getJson(route('admin.auth-audit-logs.data', ['user_id' => $this->supplier]))
            ->assertOk();
        $this->actingAs($this->admin)
            ->getJson(route('admin.auth-audit-logs.data', ['user_id' => $this->supplier->id]))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->getJson(route('admin.auth-audit-logs.data', ['user_id' => 'invalid-hash']))
            ->assertNotFound();

        $this->assertFalse(PurchasingNavigation::isSafeUrl('/purchasing/quotations/'.$this->quotation->id));
        $this->assertTrue(PurchasingNavigation::isSafeUrl('/purchasing/quotations/'.$this->quotation->getRouteKey()));
        $this->assertFalse(PurchasingNavigation::isSafeUrl('/purchasing/quotations?supplier_id='.$this->supplier->id));
        $this->assertTrue(PurchasingNavigation::isSafeUrl('/purchasing/quotations?supplier_id='.$this->supplier->getRouteKey()));
        $this->assertFalse(PurchasingNavigation::isSafeUrl('/purchasing/claims/data-action'));
        $this->assertFalse(PurchasingNavigation::isSafeUrl('/purchasing/claims/data-history'));
        $this->assertTrue(PurchasingNavigation::isSafeUrl('/purchasing/claims'));
    }

    public function test_rendered_links_comparison_history_pdf_and_chat_payload_use_hashes(): void
    {
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $this->quotation))
            ->assertOk()
            ->assertSee(route('purchasing.requisitions.show', $this->requisition), false)
            ->assertSee(route('purchasing.purchase-orders.show', $this->purchaseOrder), false);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.show', $this->requisition))
            ->assertOk()
            ->assertSee(route('purchasing.quotations.show', $this->quotation), false)
            ->assertSee('pr_id='.$this->requisition->getRouteKey(), false)
            ->assertSee($this->supplier->getRouteKey(), false);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.claims.show', $this->claim))
            ->assertOk()
            ->assertSee(route('qc.inspections.show', $this->inspection), false);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $this->purchaseOrder))
            ->assertOk()
            ->assertSee(route('shared.pdf.purchase-order', $this->purchaseOrder), false);

        $this->actingAs($this->qc)
            ->get(route('qc.inspections.show', $this->inspection))
            ->assertOk()
            ->assertSee(route('shared.pdf.qc-inspection', $this->inspection), false);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $this->requisition]))
            ->assertOk()
            ->assertSee(route('purchasing.quotations.show', $this->quotation), false)
            ->assertSee(route('purchasing.quotations.show', $this->otherQuotation), false);

        $history = $this->actingAs($this->supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get(route('supplier.price-history.historical', [
                'material_name' => $this->item->material_name,
                'view' => 'json',
            ]))
            ->assertOk();
        $this->assertSame(
            route('supplier.quotations.show', $this->quotation),
            $history->json('tableData.0.pr_url'),
        );

        $drawer = $this->actingAs($this->purchasing)
            ->getJson(route('conversations.drawer.index'))
            ->assertOk();
        $this->assertContains($this->conversation->getRouteKey(), collect($drawer->json('conversations'))->pluck('id')->all());
    }

    public function test_controller_redirects_are_canonical_hash_urls(): void
    {
        $revisionUrl = route('purchasing.quotations.show', $this->otherQuotation);
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.request-revision', $this->otherQuotation), [
                'revision_note' => 'Please revise the commercial terms.',
            ])
            ->assertRedirect($revisionUrl);

        $newPo = PurchaseOrder::create([
            'supplier_id' => $this->otherSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/08/2026/902',
            'status' => 'claim_needed',
            'created_by' => $this->purchasing->id,
        ]);
        $newInspection = QcInspection::create([
            'po_id' => $newPo->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);

        $claimResponse = $this->actingAs($this->purchasing)
            ->post(route('purchasing.claims.store'), [
                'inspection_id' => $newInspection->id,
                'description' => 'Material dimension is outside tolerance.',
                'resolution_expected' => 'Replacement material',
                'deadline' => now()->addWeek()->toDateString(),
            ]);
        $createdClaim = MaterialClaim::where('inspection_id', $newInspection->id)->sole();
        $claimResponse->assertRedirect(route('purchasing.claims.show', $createdClaim));

        $poQuotation = $this->createStandaloneQuotation('REQ/08/2026/903', $this->supplier);
        $poResponse = $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.store'), [
                'quotation_ids' => [$poQuotation->id],
                'estimated_arrival' => now()->addMonth()->toDateString(),
            ]);
        $createdPo = PurchaseOrder::whereHas('quotations', fn ($query) => $query->whereKey($poQuotation->id))->sole();
        $poResponse->assertRedirect(route('purchasing.purchase-orders.show', $createdPo));
    }

    private function createQuotation(User $supplier, string $status, float $price): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $this->requisition->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'status' => $status,
            'submitted_at' => now(),
            'estimated_delivery' => now()->addDays(30),
            'validity_period' => now()->addDays(14),
        ]);
        $quotation->items()->create([
            'pr_item_id' => $this->item->id,
            'price_per_kg' => $price,
            'amount' => $price * 100,
        ]);

        return $quotation;
    }

    private function createStandaloneQuotation(string $number, User $supplier): Quotation
    {
        $requisition = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => $number,
            'status' => 'submitted',
        ]);
        $item = PrItem::create([
            'pr_id' => $requisition->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Standalone Hashid Material',
            'shape' => 'Flat',
            'weight_needed' => 50,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $requisition->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'validity_period' => now()->addWeek(),
        ]);
        $quotation->items()->create([
            'pr_item_id' => $item->id,
            'price_per_kg' => 2,
            'amount' => 100,
        ]);

        return $quotation;
    }

    private function registerImplicitBindingRoutes(): void
    {
        $middleware = [SubstituteBindings::class, DecodeHashids::class];

        Route::middleware($middleware)->get('/_hashid-test/purchase-requisitions/{purchaseRequisition}', fn (PurchaseRequisition $purchaseRequisition) => ['id' => $purchaseRequisition->id]);
        Route::middleware($middleware)->get('/_hashid-test/quotations/{quotation}', fn (Quotation $quotation) => ['id' => $quotation->id]);
        Route::middleware($middleware)->get('/_hashid-test/purchase-orders/{purchaseOrder}', fn (PurchaseOrder $purchaseOrder) => ['id' => $purchaseOrder->id]);
        Route::middleware($middleware)->get('/_hashid-test/material-claims/{materialClaim}', fn (MaterialClaim $materialClaim) => ['id' => $materialClaim->id]);
        Route::middleware($middleware)->get('/_hashid-test/qc-inspections/{qcInspection}', fn (QcInspection $qcInspection) => ['id' => $qcInspection->id]);
        Route::middleware($middleware)->get('/_hashid-test/conversations/{conversation}', fn (Conversation $conversation) => ['id' => $conversation->id]);
        Route::middleware($middleware)->get('/_hashid-test/users/{user}', fn (User $user) => ['id' => $user->id]);
    }
}
