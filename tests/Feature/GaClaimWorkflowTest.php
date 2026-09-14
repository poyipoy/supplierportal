<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\User;
use App\Services\Ga\GaClaimService;
use App\Services\Ga\GaVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class GaClaimWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected GaClaimService $claimService;
    protected GaVerificationService $verificationService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->claimService = app(GaClaimService::class);
        $this->verificationService = app(GaVerificationService::class);
    }

    private function createEmployee(): Employee
    {
        return Employee::create([
            'name' => 'Budi Santoso',
            'department' => 'Sales',
            'bank_name' => 'BCA',
            'account_number' => '1112223334',
            'account_holder_name' => 'Budi Santoso',
            'is_active' => true,
        ]);
    }

    public function test_ga_submits_claim_and_tt_ga_is_generated(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga']);
        $employee = $this->createEmployee();

        $claim = $this->claimService->submitClaim(
            $gaUser,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_ENTERTAIN_SALES,
                'claim_date' => '2026-09-10',
                'amount' => 1500000,
                'description' => 'Dinner with client PT Krakatau',
            ],
            [
                'supporting' => UploadedFile::fake()->create('receipts.pdf', 150),
            ]
        );

        $this->assertNotNull($claim);
        $this->assertSame(GaClaim::STATUS_SUBMITTED, $claim->status);
        $this->assertStringStartsWith('CLM-', $claim->claim_number);
        $this->assertSame('1500000.00', $claim->amount);

        // TT-GA generated
        $this->assertNotNull($claim->receipt);
        $this->assertStringStartsWith('TT-GA-', $claim->receipt->receipt_number);

        // Document stored
        $this->assertCount(1, $claim->documents);
        $this->assertSame('supporting', $claim->documents->first()->document_type);
    }

    public function test_complete_ga_verification_workflow_to_ready_to_pay(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga']);
        $financeUser = User::factory()->create(['role' => 'finance']);
        $employee = $this->createEmployee();

        $claim = $this->claimService->submitClaim(
            $gaUser,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_UPD_SALES,
                'claim_date' => '2026-09-10',
                'amount' => 750000,
            ],
            [
                'supporting' => UploadedFile::fake()->create('upd_evidence.pdf', 150),
            ]
        );

        // 1. GA Basic Verification
        $basicVerified = $this->claimService->basicVerify($claim, $gaUser, 'Basic info matched.');
        $this->assertSame(GaClaim::STATUS_BASIC_VERIFIED, $basicVerified->status);
        $this->assertSame($gaUser->id, $basicVerified->basic_verified_by);

        // 2. Finance Verification -> READY_TO_PAY
        $readyToPay = $this->verificationService->financeVerify($basicVerified, $financeUser, approve: true);
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $readyToPay->status);
        $this->assertNotNull($readyToPay->ready_to_pay_at);
        $this->assertSame($financeUser->id, $readyToPay->finance_verified_by);
    }

    public function test_finance_rejects_ga_claim_to_need_revision(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga']);
        $financeUser = User::factory()->create(['role' => 'finance']);
        $employee = $this->createEmployee();

        $claim = $this->claimService->submitClaim(
            $gaUser,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
                'claim_date' => '2026-09-10',
                'amount' => 500000,
            ],
            [
                'supporting' => UploadedFile::fake()->create('receipts.pdf', 150),
            ]
        );

        // Reject without reason fails
        $this->expectException(InvalidArgumentException::class);
        $this->verificationService->financeVerify($claim, $financeUser, approve: false, reason: '');
    }

    public function test_supplier_and_purchasing_cannot_submit_or_verify_ga_claims(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $employee = $this->createEmployee();

        $this->expectException(InvalidArgumentException::class);
        $this->claimService->submitClaim(
            $supplier,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_UPD_GA,
                'claim_date' => '2026-09-10',
                'amount' => 500000,
            ],
            [
                'supporting' => UploadedFile::fake()->create('doc.pdf', 100),
            ]
        );
    }

    public function test_ga_dashboard_and_claims_render_successfully(): void
    {
        $ga = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $this->actingAs($ga)->get(route('ga.dashboard'))->assertOk();
        $this->actingAs($ga)->get(route('ga.claims.index'))->assertOk();
        $this->actingAs($ga)->get(route('ga.claims.create'))->assertOk();
    }
}
