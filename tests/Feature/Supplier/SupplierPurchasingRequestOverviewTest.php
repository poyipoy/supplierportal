<?php

namespace Tests\Feature\Supplier;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gate 2 SUP-01: read-only Purchasing Request Overview for suppliers.
 * Rows follow Gate 1 §2.2; the action must match what create()/show() allow.
 */
class SupplierPurchasingRequestOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $competitor;

    private User $purchasing;

    private ExchangeRate $rate;

    private Period $period;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->competitor = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Competitor Zeta Name']);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => now()->subDay(), 'created_by' => $admin->id]);
        $this->period = Period::create(['name' => 'Overview Period', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $admin->id]);
    }

    /** @return array<string, array{0: PurchaseRequisition, 1: ?string, 2: ?string}> name => [pr, expected action, expected route] */
    private function matrix(): array
    {
        $rows = [];
        $rows['draft'] = [$this->pr('draft'), null, null];
        $rows['rejected'] = [$this->pr('rejected'), null, null];
        $rows['open_submitted'] = [$this->pr('submitted'), 'create', 'supplier.quotations.create'];
        $rows['invited_bidding'] = [$this->pr('bidding', invite: [$this->supplier, $this->competitor]), 'create', 'supplier.quotations.create'];
        $rows['others_only'] = [$this->pr('submitted', invite: [$this->competitor]), null, null];

        $pr = $this->pr('submitted', invite: [$this->supplier]);
        $this->quotation($pr, $this->supplier, Quotation::STATUS_DRAFT);
        $rows['own_draft'] = [$pr, 'continue', 'supplier.quotations.create'];

        $pr = $this->pr('bidding', invite: [$this->supplier]);
        $this->quotation($pr, $this->supplier, Quotation::STATUS_REVISION_REQUESTED);
        $rows['own_revision'] = [$pr, 'revise', 'supplier.quotations.create'];

        // De-invited: the supplier holds a draft but only the competitor is invited now.
        $pr = $this->pr('submitted', invite: [$this->competitor]);
        $this->quotation($pr, $this->supplier, Quotation::STATUS_DRAFT);
        $rows['de_invited_draft'] = [$pr, 'view', 'supplier.quotations.show'];

        $pr = $this->pr('submitted');
        $this->quotation($pr, $this->supplier, Quotation::STATUS_SUBMITTED);
        $rows['own_submitted'] = [$pr, 'view', 'supplier.quotations.show'];

        $pr = $this->pr('completed');
        $this->quotation($pr, $this->supplier, Quotation::STATUS_ACCEPTED);
        $rows['completed_own'] = [$pr, 'view', 'supplier.quotations.show'];

        $rows['completed_none'] = [$this->pr('completed'), null, null];

        $pr = $this->pr('completed');
        $this->quotation($pr, $this->supplier, Quotation::STATUS_REJECTED)->delete();
        $rows['completed_trashed_own'] = [$pr, null, null];

        return $rows;
    }

    public function test_scope_returns_the_same_set_as_the_period_list(): void
    {
        $rows = $this->matrix();
        $expected = collect($rows)->filter(fn ($row) => $row[1] !== null)->map(fn ($row) => $row[0]->id)->sort()->values()->all();

        $scoped = PurchaseRequisition::accessibleBySupplier($this->supplier->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame($expected, $scoped);

        $periodRows = $this->actingAs($this->supplier)
            ->getJson(route('supplier.quotations.period', $this->period->id).'?draw=1&start=0&length=100', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data');
        $periodNumbers = collect($periodRows)->pluck('pr_number_display')->sort()->values()->all();
        $expectedNumbers = PurchaseRequisition::whereIn('id', $expected)->pluck('pr_number')->sort()->values()->all();
        $this->assertSame($expectedNumbers, $periodNumbers);
    }

    public function test_every_matrix_row_has_list_detail_and_action_parity(): void
    {
        $rows = $this->matrix();
        $list = $this->actingAs($this->supplier)->get(route('supplier.requests.index'))->assertOk();
        $listed = $list->viewData('requisitions')->getCollection()->pluck('id')->all();

        foreach ($rows as $name => [$pr, $action, $route]) {
            $detail = $this->actingAs($this->supplier)->get(route('supplier.requests.show', $pr));
            if ($action === null) {
                $this->assertNotContains($pr->id, $listed, "{$name} must be hidden from the list");
                $detail->assertNotFound();

                continue;
            }

            $this->assertContains($pr->id, $listed, "{$name} must be listed");
            $detail->assertOk();
            $resolved = $pr->fresh()->supplierQuotationAction($this->supplier);
            $this->assertSame($action, $resolved['type'], "{$name} action type");
            $target = $route === 'supplier.quotations.show'
                ? route($route, Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail())
                : route($route, $pr);
            $this->assertSame($target, $resolved['url'], "{$name} action url");
            $detail->assertSee($target, false);
            $this->actingAs($this->supplier)->get($target)->assertOk();
        }
    }

    public function test_detail_header_places_back_before_the_primary_action(): void
    {
        $pr = $this->pr('submitted', invite: [$this->supplier]);

        $this->actingAs($this->supplier)
            ->get(route('supplier.requests.show', $pr))
            ->assertOk()
            ->assertSeeInOrder([e(__('supplier_requests.detail.back')), 'data-supplier-request-action'], false);
    }

    public function test_detail_never_exposes_competitor_or_internal_data(): void
    {
        $pr = $this->pr('submitted', invite: [$this->supplier, $this->competitor]);
        $competitorQuotation = $this->quotation($pr, $this->competitor, Quotation::STATUS_SUBMITTED);
        $competitorQuotation->update(['reviewer_notes' => 'COMPETITOR-SECRET-REVIEW', 'payment_terms' => 'COMPETITOR-SECRET-TERMS']);
        $own = $this->quotation($pr, $this->supplier, Quotation::STATUS_DRAFT);
        $own->update(['estimated_delivery' => '2026-12-15']);

        $html = $this->actingAs($this->supplier)->get(route('supplier.requests.show', $pr))->assertOk()->getContent();

        $this->assertStringContainsString('Overview Material', $html);
        $this->assertStringContainsString($pr->pr_number, $html);
        foreach (['COMPETITOR-SECRET-REVIEW', 'COMPETITOR-SECRET-TERMS', 'Competitor Zeta Name', $this->purchasing->name, $competitorQuotation->hash] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_raw_integer_ids_unrelated_roles_and_local_suppliers_are_refused(): void
    {
        $pr = $this->pr('submitted');

        $this->actingAs($this->supplier)->get(route('supplier.requests.show', ['pr_id' => $pr->id]))->assertNotFound();
        $this->actingAs($this->purchasing)->get(route('supplier.requests.index'))->assertForbidden();

        $local = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $local->supplierScopes()->delete();
        $local->supplierScopes()->create(['scope' => 'local']);
        $this->actingAs($local)->get(route('supplier.requests.index'))->assertForbidden();
        $this->actingAs($local)->get(route('supplier.requests.show', $pr))->assertForbidden();
    }

    public function test_list_filters_by_pr_number_period_and_my_quotation_status(): void
    {
        $open = $this->pr('submitted');
        $drafted = $this->pr('submitted');
        $this->quotation($drafted, $this->supplier, Quotation::STATUS_DRAFT);
        $otherPeriod = Period::create(['name' => 'Other Period', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $elsewhere = $this->pr('submitted', period: $otherPeriod);

        $ids = fn (array $query) => $this->actingAs($this->supplier)
            ->get(route('supplier.requests.index', $query))->assertOk()
            ->viewData('requisitions')->getCollection()->pluck('id')->sort()->values()->all();

        $this->assertSame([$open->id], $ids(['search' => $open->pr_number]));
        $this->assertSame([$elsewhere->id], $ids(['period_id' => $otherPeriod->id]));
        $this->assertSame([$drafted->id], $ids(['status' => 'draft']));
        $this->assertSame(collect([$open->id, $elsewhere->id])->sort()->values()->all(), $ids(['status' => 'unresponded']));
        $this->actingAs($this->supplier)->get(route('supplier.requests.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }

    public function test_list_query_count_does_not_grow_with_requisitions(): void
    {
        $this->pr('submitted');
        $this->quotation($this->pr('submitted', invite: [$this->supplier]), $this->supplier, Quotation::STATUS_SUBMITTED);

        DB::enableQueryLog();
        $this->actingAs($this->supplier)->get(route('supplier.requests.index'))->assertOk();
        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 6; $i++) {
            $pr = $this->pr($i % 2 ? 'bidding' : 'submitted', invite: $i % 3 ? [$this->supplier] : []);
            $this->quotation($pr, $this->supplier, $i % 2 ? Quotation::STATUS_SUBMITTED : Quotation::STATUS_ACCEPTED);
        }
        DB::flushQueryLog();
        $this->actingAs($this->supplier)->get(route('supplier.requests.index'))->assertOk();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    /** @param  array<int, User>  $invite */
    private function pr(string $status, array $invite = [], ?Period $period = null): PurchaseRequisition
    {
        $this->sequence++;
        $pr = PurchaseRequisition::create([
            'period_id' => ($period ?? $this->period)->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => sprintf('REQ/10/2026/%03d', $this->sequence),
            'status' => $status,
            'notes' => 'Overview notes '.$this->sequence,
        ]);
        PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7208.51.00',
            'material_name' => 'Overview Material',
            'shape' => 'Flat',
            'thickness' => 12,
            'width' => 1500,
            'length' => 6000,
            'quantity' => 4,
            'weight_needed' => 848.16,
        ]);
        foreach ($invite as $supplier) {
            $pr->invitedSuppliers()->attach($supplier->id, ['invited_at' => now()]);
        }

        return $pr;
    }

    private function quotation(PurchaseRequisition $pr, User $supplier, string $status): Quotation
    {
        return Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'status' => $status,
        ]);
    }
}
