<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Payment\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FinanceDrpCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'Candidate Vendor']);
        SupplierBankAccount::create([
            'supplier_id' => $this->supplier->id, 'bank_name' => 'BCA',
            'account_number' => '1234', 'account_holder_name' => 'Candidate Vendor',
            'status' => SupplierBankAccount::STATUS_VERIFIED, 'activated_at' => now(),
        ]);
    }

    private function invoice(string $due, string $ready): LocalInvoice
    {
        return LocalInvoice::create([
            'submission_number' => uniqid('SUB-'), 'supplier_id' => $this->supplier->id,
            'invoice_number' => uniqid('INV-'), 'invoice_date' => '2026-10-01',
            'po_number' => 'PO-TEST', 'invoice_amount' => '1000', 'tax_amount' => 0,
            'status' => LocalInvoice::STATUS_READY_TO_PAY, 'due_date' => $due,
            'ready_to_pay_at' => $ready, 'submitted_at' => now(),
        ]);
    }

    public function test_supplier_candidate_sort_whitelist_and_default_order(): void
    {
        $later = $this->invoice('2026-10-20', '2026-10-01 18:00:00');
        $earlier = $this->invoice('2026-10-10', '2026-10-03 18:00:00');
        $cases = [
            [[], [$earlier->id, $later->id]],
            [['sort' => 'due_date', 'direction' => 'desc'], [$later->id, $earlier->id]],
            [['sort' => 'verification_date', 'direction' => 'asc'], [$later->id, $earlier->id]],
            [['sort' => 'verification_date', 'direction' => 'desc'], [$earlier->id, $later->id]],
            [['sort' => 'id desc; drop table users', 'direction' => 'nonsense'], [$earlier->id, $later->id]],
            [['sort' => 'id', 'direction' => 'desc'], [$earlier->id, $later->id]],
            [['direction' => 'desc'], [$earlier->id, $later->id]],
        ];
        foreach ($cases as [$query, $expected]) {
            $this->actingAs($this->finance)->get(route('finance.drp.supplier', $query))
                ->assertOk()->assertViewHas('eligibleInvoices', fn ($rows) => $rows->pluck('id')->all() === $expected);
        }
    }

    public function test_supplier_date_ranges_use_business_day_boundaries_and_and_semantics(): void
    {
        config(['app.business_timezone' => 'Asia/Jakarta']);
        $match = $this->invoice('2026-10-10', '2026-10-01 17:00:00');
        $before = $this->invoice('2026-10-10', '2026-10-01 16:59:59');
        $after = $this->invoice('2026-10-10', '2026-10-02 17:00:00');
        $otherDue = $this->invoice('2026-10-11', '2026-10-02 16:59:59');
        $cases = [
            [['due_date_from' => '2026-10-11'], [$otherDue->id]],
            [['due_date_to' => '2026-10-10'], [$match->id, $before->id, $after->id]],
            [['verification_date_from' => '2026-10-02'], [$match->id, $after->id, $otherDue->id]],
            [['verification_date_to' => '2026-10-02'], [$match->id, $before->id, $otherDue->id]],
            [['due_date_from' => '2026-10-10', 'due_date_to' => '2026-10-10',
                'verification_date_from' => '2026-10-02', 'verification_date_to' => '2026-10-02'], [$match->id]],
        ];
        foreach ($cases as [$query, $expected]) {
            $this->actingAs($this->finance)->get(route('finance.drp.supplier', $query))
                ->assertOk()->assertViewHas('eligibleInvoices', fn ($rows) => $rows->pluck('id')->all() === $expected);
        }
    }

    public function test_candidate_filters_do_not_filter_batch_history_and_preserve_supplier(): void
    {
        $invoice = $this->invoice('2026-10-10', '2026-10-01 17:00:00');
        $batch = app(PaymentBatchService::class)->createSupplierBatch($this->finance, [$invoice->id]);
        $this->actingAs($this->finance)->get(route('finance.drp.supplier', [
            'supplier_id' => $this->supplier->hash, 'due_date_from' => '2099-01-01',
        ]))->assertOk()->assertViewHas('eligibleInvoices', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('batches', fn ($rows) => $rows->pluck('id')->all() === [$batch->id])
            ->assertViewHas('supplierFilter', fn ($supplier) => $supplier->id === $this->supplier->id);
    }

    private function claim(): GaClaim
    {
        $employee = Employee::create([
            'name' => uniqid('Employee '), 'department' => 'GA', 'bank_name' => 'Mandiri',
            'account_number' => '789', 'account_holder_name' => 'Employee', 'is_active' => true,
        ]);

        return GaClaim::create([
            'claim_number' => uniqid('GA-'), 'employee_id' => $employee->id,
            'claim_type' => 'Reimburse/Claim', 'claim_date' => '2026-10-01',
            'amount' => '1000', 'description' => 'Test', 'submitted_by' => $this->finance->id,
            'status' => GaClaim::STATUS_READY_TO_PAY, 'ready_to_pay_at' => now(), 'submitted_at' => now(),
        ]);
    }

    public function test_finance_selects_multiple_ga_claims_and_reserved_claims_leave_pool(): void
    {
        $first = $this->claim();
        $second = $this->claim();
        $notReady = $this->claim();
        $notReady->update(['status' => GaClaim::STATUS_SUBMITTED]);
        $this->actingAs($this->finance)->get(route('finance.drp.ga'))->assertOk()
            ->assertViewHas('eligibleClaims', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $second->id]);
        $this->post('/finance/drp-ga', ['claim_ids' => [$first->id, $second->id]])->assertRedirect();
        $batch = PaymentBatch::where('batch_type', PaymentBatch::TYPE_GA)->firstOrFail();
        $this->assertSame('0.00', $batch->total_bank_fee);
        $this->assertSame(2, $batch->groups->sum(fn ($group) => $group->items()->count()));
        $this->get(route('finance.drp.ga'))->assertOk()
            ->assertViewHas('eligibleClaims', fn ($rows) => $rows->isEmpty());
    }

    public function test_ga_actor_cannot_create_batch_through_http_or_service(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $claim = $this->claim();
        $this->actingAs($ga)->post('/finance/drp-ga', ['claim_ids' => [$claim->id]])->assertForbidden();
        $this->get('/ga/drp-draft')->assertNotFound();
        $this->post('/ga/drp-draft', ['claim_ids' => [$claim->id]])->assertNotFound();
        $this->expectException(InvalidArgumentException::class);
        app(PaymentBatchService::class)->createGaBatch($ga, [$claim->id]);
    }

    public function test_ga_batch_revalidates_employee_bank_and_rolls_back_batch(): void
    {
        $claim = $this->claim();
        $claim->employee->update(['account_number' => '']);
        try {
            app(PaymentBatchService::class)->createGaBatch($this->finance, [$claim->id]);
            $this->fail('Incomplete bank details must reject the batch.');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('finance.batch_validation.employee_bank', ['number' => $claim->claim_number]), $e->getMessage());
        }
        $this->assertSame(0, PaymentBatch::count());
    }

    public function test_ga_candidate_search_and_canonical_type_filter_do_not_change_history(): void
    {
        $match = $this->claim();
        $other = $this->claim();
        $other->update(['claim_type' => GaClaim::TYPE_BUSINESS_TRAVEL]);
        $match->employee->update(['name' => 'Unique Payee']);
        $batch = app(PaymentBatchService::class)->createGaBatch($this->finance, [$other->id]);
        $this->actingAs($this->finance)->get(route('finance.drp.ga', [
            'q' => 'Unique Payee', 'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
        ]))->assertOk()->assertViewHas('eligibleClaims', fn ($rows) => $rows->pluck('id')->all() === [$match->id])
            ->assertViewHas('batches', fn ($rows) => $rows->pluck('id')->all() === [$batch->id]);
        $match->update(['claim_number' => 'GA-SEARCH-ZERO']);
        $match->employee->update(['name' => 'Payee 0']);
        $this->get(route('finance.drp.ga', ['q' => '0']))->assertOk()
            ->assertViewHas('eligibleClaims', fn ($rows) => $rows->pluck('id')->all() === [$match->id]);
    }
}
