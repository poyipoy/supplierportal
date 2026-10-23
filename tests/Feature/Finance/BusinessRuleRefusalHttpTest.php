<?php

namespace Tests\Feature\Finance;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\Payment\PaymentBatchService;
use App\Services\UserPreferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * Finance and GA services refuse invalid requests with translated business-rule
 * messages. Those refusals used to escape the controllers as plain
 * RuntimeException/InvalidArgumentException and render as HTTP 500, hiding the
 * reason. They now come back as feedback (redirect with an error flash, or 422
 * JSON), while unexpected failures keep the generic, non-revealing response.
 */
class BusinessRuleRefusalHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->create(['role' => 'finance']);
    }

    public function test_drp_for_a_supplier_without_a_verified_bank_account_is_refused_with_the_reason(): void
    {
        $supplier = $this->localSupplier(withBank: false);
        $invoice = $this->readyToPayInvoice($supplier, 'INV-NOBANK-1');

        $this->actingAs($this->finance)
            ->from(route('finance.drp.supplier'))
            ->post(route('finance.drp.supplier.create'), ['invoice_ids' => [$invoice->id]])
            ->assertRedirect(route('finance.drp.supplier'))
            ->assertSessionHas('error', __('finance.batch_validation.supplier_bank', ['name' => $supplier->name]));

        // The refusal still rolls back the whole batch, including its number.
        $this->assertDatabaseCount('payment_batches', 0);
        $this->assertDatabaseCount('payment_items', 0);
        $this->assertSame(0, (int) DB::table('local_invoice_sequences')->sum('last_number'));
    }

    public function test_json_clients_receive_the_refusal_as_unprocessable_content(): void
    {
        $supplier = $this->localSupplier(withBank: false);
        $invoice = $this->readyToPayInvoice($supplier, 'INV-NOBANK-2');

        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.supplier.create'), ['invoice_ids' => [$invoice->id]])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => __('finance.batch_validation.supplier_bank', ['name' => $supplier->name]),
            ]);
    }

    public function test_the_refusal_is_shown_in_the_language_of_the_user(): void
    {
        app(UserPreferenceService::class)->saveLocale($this->finance, 'id');
        $supplier = $this->localSupplier(withBank: false);
        $invoice = $this->readyToPayInvoice($supplier, 'INV-NOBANK-3');
        $indonesian = trans('finance.batch_validation.supplier_bank', ['name' => $supplier->name], 'id');
        $this->assertNotSame(trans('finance.batch_validation.supplier_bank', ['name' => $supplier->name], 'en'), $indonesian);

        $this->actingAs($this->finance)
            ->from(route('finance.drp.supplier'))
            ->post(route('finance.drp.supplier.create'), ['invoice_ids' => [$invoice->id]])
            ->assertSessionHas('error', $indonesian);
    }

    public function test_an_invoice_already_reserved_by_an_active_drp_is_refused(): void
    {
        $supplier = $this->localSupplier(withBank: true);
        $invoice = $this->readyToPayInvoice($supplier, 'INV-RESERVED-1');
        app(PaymentBatchService::class)->createSupplierBatch($this->finance, [$invoice->id]);

        $this->actingAs($this->finance)
            ->from(route('finance.drp.supplier'))
            ->post(route('finance.drp.supplier.create'), ['invoice_ids' => [$invoice->id]])
            ->assertRedirect(route('finance.drp.supplier'))
            ->assertSessionHas('error', __('finance.batch_validation.invoice_reserved'));

        $this->assertDatabaseCount('payment_batches', 1);
    }

    public function test_a_stale_ready_to_pay_approval_is_refused_without_side_effects(): void
    {
        $invoice = $this->readyToPayInvoice($this->localSupplier(withBank: true), 'INV-STALE-1');
        $histories = $invoice->statusHistories()->count();

        $this->actingAs($this->finance)
            ->from(route('finance.invoices.show', $invoice))
            ->post(route('finance.invoices.approve-ready-to-pay', $invoice))
            ->assertRedirect(route('finance.invoices.show', $invoice))
            ->assertSessionHas('error', __('local_invoice.validation.approve_status', ['status' => LocalInvoice::STATUS_READY_TO_PAY]));

        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->fresh()->status);
        $this->assertSame($histories, $invoice->statusHistories()->count());
    }

    public function test_a_revision_request_in_a_state_that_no_longer_allows_it_is_refused(): void
    {
        $invoice = $this->readyToPayInvoice($this->localSupplier(withBank: true), 'INV-STALE-2');

        $this->actingAs($this->finance)
            ->from(route('finance.invoices.show', $invoice))
            ->post(route('finance.invoices.request-revision', $invoice), ['notes' => 'Tax invoice is wrong.'])
            ->assertRedirect(route('finance.invoices.show', $invoice))
            ->assertSessionHas('error', __('local_invoice.validation.revision_status', ['status' => LocalInvoice::STATUS_READY_TO_PAY]));

        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->fresh()->status);
    }

    public function test_removing_an_item_from_a_finalized_drp_is_refused(): void
    {
        $supplier = $this->localSupplier(withBank: true);
        $invoice = $this->readyToPayInvoice($supplier, 'INV-FINAL-1');
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-REFUSAL-001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 100000,
            'total_bank_fee' => 0,
            'total_net_amount' => 100000,
            'created_by' => $this->finance->id,
        ]);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $supplier->id,
            'payee_name' => 'PT Refusal Supplier',
            'bank_name' => 'BCA',
            'account_number' => '123456',
            'account_holder_name' => 'PT Refusal Supplier',
            'subtotal_amount' => 100000,
            'bank_fee' => 0,
            'net_payment_amount' => 100000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 100000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->finance)
            ->from(route('finance.drp.show', $batch))
            ->post(route('finance.drp.remove-item', $item), ['reason' => 'Supplier asked to hold it.'])
            ->assertRedirect(route('finance.drp.show', $batch))
            ->assertSessionHas('error', __('finance.batch_validation.removal_draft', ['status' => PaymentBatch::STATUS_FINALIZED]));

        $this->assertSame(PaymentItem::STATUS_ACTIVE, $item->fresh()->status);
    }

    public function test_finance_verification_of_a_ga_claim_before_basic_verification_is_refused(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga']);
        $employee = Employee::create([
            'name' => 'Budi Santoso',
            'department' => 'Sales',
            'bank_name' => 'BCA',
            'account_number' => '1112223334',
            'account_holder_name' => 'Budi Santoso',
            'is_active' => true,
        ]);
        $claim = GaClaim::create([
            'claim_number' => 'CLM-REFUSAL-1',
            'employee_id' => $employee->id,
            'submitted_by' => $gaUser->id,
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
            'claim_date' => '2026-10-01',
            'amount' => '1000.00',
            'description' => 'Taxi',
            'status' => GaClaim::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->finance)
            ->from(route('finance.ga-claims.show', $claim))
            ->post(route('finance.ga-claims.verify', $claim), ['approve' => 1])
            ->assertRedirect(route('finance.ga-claims.show', $claim))
            ->assertSessionHas('error', __('ga.validation.basic_required'));

        $this->assertSame(GaClaim::STATUS_SUBMITTED, $claim->fresh()->status);
    }

    public function test_an_unexpected_database_failure_stays_generic_and_does_not_reveal_sql(): void
    {
        config(['app.debug' => false]);
        $invoice = $this->readyToPayInvoice($this->localSupplier(withBank: true), 'INV-DBFAIL-1');
        $this->mock(InvoiceVerificationService::class, fn ($mock) => $mock->shouldReceive('verifySectionA')->andThrow(
            new QueryException('mysql', 'select secret_payroll_column from hidden_ledger', [], new PDOException('SQLSTATE[42S22]: Column not found'))
        ));
        $payload = [
            'invoice_check' => 'OK',
            'tax_invoice_check' => 'OK',
            'po_check' => 'OK',
            'delivery_note_check' => 'OK',
            'gr_check' => 'OK',
        ];

        $this->actingAs($this->finance)
            ->from(route('finance.invoices.show', $invoice))
            ->post(route('finance.invoices.verify-section-a', $invoice), $payload)
            ->assertStatus(500)
            ->assertDontSee('secret_payroll_column')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($this->finance)
            ->postJson(route('finance.invoices.verify-section-a', $invoice), $payload)
            ->assertStatus(500)
            ->assertDontSee('secret_payroll_column')
            ->assertDontSee('SQLSTATE');
    }

    private function localSupplier(bool $withBank): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Refusal Supplier',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        if ($withBank) {
            SupplierBankAccount::create([
                'supplier_id' => $user->id,
                'bank_name' => 'BCA',
                'account_number' => '123456',
                'account_holder_name' => 'PT Refusal Supplier',
                'status' => SupplierBankAccount::STATUS_VERIFIED,
                'activated_at' => now(),
            ]);
        }

        return $user;
    }

    private function readyToPayInvoice(User $supplier, string $number): LocalInvoice
    {
        return LocalInvoice::create([
            'submission_number' => 'SUB-'.$number,
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-REFUSAL',
            'invoice_amount' => 100000,
            'tax_amount' => 0,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_at' => now(),
            'ready_to_pay_at' => now(),
        ]);
    }
}
