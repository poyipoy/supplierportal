<?php

namespace Tests\Feature\PaymentVoucher;

use App\Models\Employee;
use App\Models\LocalFinanceAuditLog;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherDocument;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Payment\PaymentVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class ManualPaymentVoucherWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $purchasing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->finance = User::factory()->create([
            'name' => 'Finance Officer',
            'role' => 'finance',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
        $this->purchasing = User::factory()->create([
            'name' => 'Purchasing Buyer',
            'role' => 'purchasing',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
        $this->admin = User::factory()->create([
            'name' => 'Portal Admin',
            'role' => 'admin',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
    }

    private function createVerifiedSupplier(string $name = 'PT Baja Nusantara'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
        SupplierScope::firstOrCreate(['supplier_id' => $user->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $user->id,
            'company_name' => $name,
            'npwp' => '01.234.567.8-901.000',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);
        SupplierBankAccount::create([
            'supplier_id' => $user->id,
            'bank_name' => 'BANK BCA',
            'account_number' => '8881234567',
            'account_holder_name' => $name,
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'verified_by' => $this->finance->id,
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        return $user;
    }

    private function createEmployee(bool $active = true): Employee
    {
        return Employee::create([
            'name' => 'Rina Kartika',
            'department' => 'Purchasing',
            'bank_name' => 'BANK MANDIRI',
            'account_number' => '1300099887766',
            'account_holder_name' => 'Rina Kartika',
            'is_active' => $active,
        ]);
    }

    public function test_finance_can_create_supplier_voucher_with_authoritative_bank_snapshot_and_async_upload(): void
    {
        $supplier = $this->createVerifiedSupplier('PT Baja Nusantara');

        $response = $this->actingAs($this->finance)
            ->postJson(route('finance.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_SUPPLIER,
                'payee_supplier_hash' => $supplier->hash,
                // Attempt client tampering on payee/bank/total
                'payee_name' => 'Hacker Corp',
                'bank_name' => 'FAKE BANK',
                'account_number' => '0000000',
                'account_holder_name' => 'Hacker',
                'net_amount' => '1.00',
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_BANK,
                'reference_number' => 'PO/11/2026/001',
                'description' => 'Pembayaran DP Pengadaan Material Khusus',
                'notes' => 'Transfer hari ini',
                'dpp_amount' => '10000000.00',
                'ppn_amount' => '1100000.00',
                'pph_amount' => '200000.00',
                'bank_fee' => '2500.00',
                'supporting' => NativeFileFixtures::upload('supporting-memo.pdf', 15),
            ]);

        $voucher = PaymentVoucher::with('documents')->firstOrFail();

        $response->assertOk()
            ->assertJson(['redirect' => route('finance.payment-vouchers.show', $voucher)]);

        $this->assertMatchesRegularExpression('/^VC\/\d{4}\/\d{4}$/', $voucher->voucher_number);
        $this->assertSame(PaymentVoucher::STATUS_FINAL, $voucher->status);
        $this->assertSame(PaymentVoucher::DEPT_FINANCE, $voucher->source_department);
        $this->assertSame($supplier->id, $voucher->payee_supplier_id);
        $this->assertSame('PT Baja Nusantara', $voucher->payee_name_snapshot);
        $this->assertSame('BANK BCA', $voucher->bank_name_snapshot);
        $this->assertSame('8881234567', $voucher->bank_account_snapshot);
        $this->assertSame('PT Baja Nusantara', $voucher->bank_account_holder_snapshot);
        $this->assertSame('01.234.567.8-901.000', $voucher->npwp_snapshot);
        // Server BCMath: 10,000,000 + 1,100,000 - 200,000 - 2,500 = 10,897,500.00
        $this->assertSame('10897500.00', $voucher->net_amount);
        $this->assertStringContainsString('Rupiah', $voucher->terbilang_snapshot);

        $doc = $voucher->documents->firstOrFail();
        Storage::disk('private')->assertExists($doc->file_path);
        $this->assertNotNull($doc->file_inspection_id);

        $this->assertTrue(
            LocalFinanceAuditLog::where('auditable_type', PaymentVoucher::class)
                ->where('auditable_id', $voucher->id)
                ->where('action', 'manual_voucher_issued')
                ->exists()
        );

        // Redirected show page immediately surfaces Generate PDF actions
        $this->actingAs($this->finance)
            ->get(route('finance.payment-vouchers.show', $voucher))
            ->assertOk()
            ->assertSee($voucher->voucher_number)
            ->assertSee(route('finance.payment-vouchers.print', $voucher));
    }

    public function test_supplier_without_verified_bank_is_rejected_for_bank_method_but_allowed_for_kas(): void
    {
        $importSupplier = User::factory()->create([
            'name' => 'Daido Japan Co., Ltd.',
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
        SupplierScope::firstOrCreate(['supplier_id' => $importSupplier->id, 'scope' => 'import']);
        Supplier::create([
            'user_id' => $importSupplier->id,
            'company_name' => 'Daido Japan Co., Ltd.',
            'npwp' => null,
            'vendor_category' => 'Barang',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);

        // BANK without verified bank must fail 422
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_SUPPLIER,
                'payee_supplier_hash' => $importSupplier->hash,
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_BANK,
                'description' => 'Pembayaran tanpa rekening verified',
                'dpp_amount' => '500000.00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payee_supplier_hash');

        // KAS succeeds and clears bank fields
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_SUPPLIER,
                'payee_supplier_hash' => $importSupplier->hash,
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_KAS,
                'description' => 'Kas tunai sampel material',
                'dpp_amount' => '500000.00',
            ])
            ->assertRedirect();

        $voucher = PaymentVoucher::latest('id')->firstOrFail();
        $this->assertSame(PaymentVoucher::DEPT_PURCHASING, $voucher->source_department);
        $this->assertNull($voucher->bank_name_snapshot);
        $this->assertNull($voucher->bank_account_snapshot);
        $this->assertSame('500000.00', $voucher->net_amount);
    }

    public function test_purchasing_can_issue_employee_and_manual_vouchers_and_finance_shares_register(): void
    {
        $employee = $this->createEmployee(true);
        $inactiveEmployee = $this->createEmployee(false);

        // Inactive employee rejected
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_EMPLOYEE,
                'payee_employee_id' => $inactiveEmployee->id,
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_BANK,
                'description' => 'Uang muka karyawan nonaktif',
                'dpp_amount' => '250000.00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payee_employee_id');

        // Active employee accepted
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_EMPLOYEE,
                'payee_employee_id' => $employee->id,
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_BANK,
                'description' => 'Uang muka dinas inspeksi pelabuhan',
                'dpp_amount' => '1250000.00',
            ])
            ->assertRedirect();

        $empVoucher = PaymentVoucher::where('payee_type', PaymentVoucher::PAYEE_EMPLOYEE)->firstOrFail();
        $this->assertSame('Rina Kartika', $empVoucher->payee_name_snapshot);
        $this->assertSame('Purchasing', $empVoucher->payee_department_snapshot);
        $this->assertSame('BANK MANDIRI', $empVoucher->bank_name_snapshot);
        $this->assertSame('1300099887766', $empVoucher->bank_account_snapshot);

        // Manual payee via Finance (BANK)
        $this->actingAs($this->finance)
            ->post(route('finance.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_MANUAL,
                'payee_name' => 'PT Ekspedisi Pelabuhan Jaya',
                'npwp' => '09.111.222.3-444.000',
                'bank_name' => 'BANK BRI',
                'account_number' => '001122334455',
                'account_holder_name' => 'PT Ekspedisi Pelabuhan Jaya',
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_BANK,
                'description' => 'Biaya PPJK & PIB Kontainer Impor',
                'dpp_amount' => '25000000.00',
                'supporting' => NativeFileFixtures::upload('pib-doc.pdf', 10),
            ])
            ->assertRedirect();

        $manualVoucher = PaymentVoucher::where('payee_type', PaymentVoucher::PAYEE_MANUAL)->firstOrFail();
        $doc = $manualVoucher->documents()->firstOrFail();

        // Shared register across Finance and Purchasing (Option C)
        $this->actingAs($this->finance)
            ->get(route('finance.payment-vouchers.index'))
            ->assertOk()
            ->assertSee($empVoucher->voucher_number)
            ->assertSee($manualVoucher->voucher_number);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.payment-vouchers.index', ['department' => PaymentVoucher::DEPT_FINANCE]))
            ->assertOk()
            ->assertSee($manualVoucher->voucher_number)
            ->assertDontSee($empVoucher->voucher_number);

        // Purchasing can view detail and download supporting doc of Finance voucher
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.payment-vouchers.show', $manualVoucher))
            ->assertOk()
            ->assertSee('PT Ekspedisi Pelabuhan Jaya');

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.payment-vouchers.documents.show', [$manualVoucher, $doc]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_shared_sequence_prevents_duplicates_with_existing_drp_sequence(): void
    {
        $period = '2611';
        DB::table('payment_voucher_sequences')->insert([
            'period' => $period,
            'last_number' => 41,
        ]);

        // Seed existing DRP/manual number check at next slot
        $numberFromService = app(PaymentVoucherService::class)->nextVoucherNumber();
        $this->assertMatchesRegularExpression('/^VC\/\d{4}\/\d{4}$/', $numberFromService);

        $this->actingAs($this->finance)
            ->post(route('finance.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_MANUAL,
                'payee_name' => 'PLN Cikarang',
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_KAS,
                'description' => 'Pembayaran utilitas kantor',
                'dpp_amount' => '1500000.00',
            ])
            ->assertRedirect();

        $voucher = PaymentVoucher::firstOrFail();
        $this->assertNotSame($numberFromService, $voucher->voucher_number);
    }

    public function test_net_amount_must_be_positive_and_validates_tax_overflow(): void
    {
        $this->actingAs($this->finance)
            ->postJson(route('finance.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_MANUAL,
                'payee_name' => 'Vendor Test',
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_KAS,
                'description' => 'Test PPh melebihi DPP',
                'dpp_amount' => '100000.00',
                'ppn_amount' => '0',
                'pph_amount' => '100000.00',
                'bank_fee' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dpp_amount');
    }

    public function test_void_enforces_department_policy_reason_and_single_transition(): void
    {
        // Finance creates voucher
        $this->actingAs($this->finance)->post(route('finance.payment-vouchers.store'), [
            'payee_type' => PaymentVoucher::PAYEE_MANUAL,
            'payee_name' => 'Finance Payee',
            'voucher_date' => '2026-11-11',
            'payment_method' => PaymentVoucher::METHOD_KAS,
            'description' => 'Finance Voucher',
            'dpp_amount' => '300000.00',
        ]);
        $financeVoucher = PaymentVoucher::where('source_department', PaymentVoucher::DEPT_FINANCE)->firstOrFail();

        // Purchasing creates voucher
        $this->actingAs($this->purchasing)->post(route('purchasing.payment-vouchers.store'), [
            'payee_type' => PaymentVoucher::PAYEE_MANUAL,
            'payee_name' => 'Purchasing Payee',
            'voucher_date' => '2026-11-11',
            'payment_method' => PaymentVoucher::METHOD_KAS,
            'description' => 'Purchasing Voucher',
            'dpp_amount' => '450000.00',
        ]);
        $purchasingVoucher = PaymentVoucher::where('source_department', PaymentVoucher::DEPT_PURCHASING)->firstOrFail();

        // Purchasing cannot void Finance's voucher
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.payment-vouchers.void', $financeVoucher), [
                'void_reason' => 'Coba batalkan milik finance',
            ])
            ->assertForbidden();

        // Reason is mandatory
        $this->actingAs($this->purchasing)
            ->from(route('purchasing.payment-vouchers.show', $purchasingVoucher))
            ->post(route('purchasing.payment-vouchers.void', $purchasingVoucher), [
                'void_reason' => '',
            ])
            ->assertSessionHasErrors('void_reason');

        // Purchasing CAN void Purchasing's own voucher
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.payment-vouchers.void', $purchasingVoucher), [
                'void_reason' => 'Nominal salah ketik',
            ])
            ->assertRedirect(route('purchasing.payment-vouchers.show', $purchasingVoucher));

        $purchasingVoucher->refresh();
        $this->assertSame(PaymentVoucher::STATUS_CANCELLED, $purchasingVoucher->status);
        $this->assertSame('Nominal salah ketik', $purchasingVoucher->void_reason);
        $this->assertSame($this->purchasing->id, $purchasingVoucher->voided_by);

        // Already cancelled cannot be voided again (policy denies)
        $this->actingAs($this->finance)
            ->post(route('finance.payment-vouchers.void', $purchasingVoucher), [
                'void_reason' => 'Double void attempt',
            ])
            ->assertForbidden();

        // Finance CAN void Finance's voucher
        $this->actingAs($this->finance)
            ->post(route('finance.payment-vouchers.void', $financeVoucher), [
                'void_reason' => 'Transaksi dibatalkan',
            ])
            ->assertRedirect();
    }

    public function test_role_and_hashid_security_boundaries_and_duplicate_prefill(): void
    {
        $this->actingAs($this->finance)->post(route('finance.payment-vouchers.store'), [
            'payee_type' => PaymentVoucher::PAYEE_MANUAL,
            'payee_name' => 'PT Duplicate Source',
            'voucher_date' => '2026-11-11',
            'payment_method' => PaymentVoucher::METHOD_KAS,
            'reference_number' => 'MEMO/2026/10',
            'description' => 'Pembayaran awal yang akan diduplikasi',
            'dpp_amount' => '900000.00',
        ]);
        $voucher = PaymentVoucher::firstOrFail();

        // Non-permitted roles are blocked 403
        foreach (['supplier', 'qc', 'ga', 'accounting'] as $role) {
            $actor = User::factory()->create([
                'role' => $role,
                'is_active' => true,
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            ]);

            $this->actingAs($actor)->get(route('finance.payment-vouchers.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('purchasing.payment-vouchers.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('finance.payment-vouchers.show', $voucher))->assertForbidden();
            $this->actingAs($actor)->get(route('purchasing.payment-vouchers.print', $voucher))->assertForbidden();
        }

        // Raw numeric ID on hashed routes returns 404
        $this->actingAs($this->finance)->get('/finance/payment-vouchers/'.$voucher->id)->assertNotFound();
        $this->actingAs($this->finance)->get('/finance/payment-vouchers/'.$voucher->id.'/print')->assertNotFound();
        $this->actingAs($this->purchasing)->get('/purchasing/payment-vouchers/'.$voucher->id)->assertNotFound();

        // Admin is allowed via finance group
        $this->actingAs($this->admin)->get(route('finance.payment-vouchers.show', $voucher))->assertOk();

        // Duplicate prefill copies fields safely without copying voucher number
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.payment-vouchers.create', ['duplicate_from' => $voucher->hash]))
            ->assertOk()
            ->assertSee('PT Duplicate Source')
            ->assertSee('MEMO/2026/10')
            ->assertDontSee($voucher->voucher_number);
    }

    public function test_rejected_upload_leaves_no_orphan_file_in_private_storage(): void
    {
        $invalid = UploadedFile::fake()->createWithContent('bad.pdf', 'not-a-pdf-payload');

        $this->actingAs($this->finance)
            ->postJson(route('finance.payment-vouchers.store'), [
                'payee_type' => PaymentVoucher::PAYEE_MANUAL,
                'payee_name' => 'PT Invalid File',
                'voucher_date' => '2026-11-11',
                'payment_method' => PaymentVoucher::METHOD_KAS,
                'description' => 'Upload gagal inspeksi',
                'dpp_amount' => '100000.00',
                'supporting' => $invalid,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supporting');

        $this->assertSame(0, PaymentVoucher::count());
        $this->assertSame(0, PaymentVoucherDocument::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
