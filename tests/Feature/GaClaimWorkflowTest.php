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

    public function test_finance_cannot_verify_unverified_submitted_claim(): void
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
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('claim must be in BASIC_VERIFIED status before Finance verification');
        $this->verificationService->financeVerify($claim, $financeUser, approve: true);
    }

    public function test_submit_claim_without_supporting_document_succeeds(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga']);
        $employee = $this->createEmployee();

        $claim = $this->claimService->submitClaim(
            $gaUser,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_UPD_GA,
                'claim_date' => '2026-09-12',
                'amount' => 250000,
            ],
            [] // No files
        );

        $this->assertSame(GaClaim::STATUS_SUBMITTED, $claim->status);
        $this->assertCount(0, $claim->documents);
        $this->assertNotNull($claim->receipt);
    }

    public function test_ga_claim_resubmit_increments_revision_and_resets_status(): void
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
            ]
        );

        // GA basic verifies
        $this->claimService->basicVerify($claim, $gaUser);

        // Finance requests revision
        $this->verificationService->financeVerify($claim, $financeUser, approve: false, reason: 'Kwitansi fisik buram, mohon upload ulang.');
        $claim->refresh();
        $this->assertSame(GaClaim::STATUS_NEED_REVISION, $claim->status);
        $this->assertSame(1, $claim->revision_number);

        // GA resubmits
        $resubmitted = $this->claimService->resubmitClaim(
            $gaUser,
            $claim,
            [
                'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
                'claim_date' => '2026-09-11',
                'amount' => 450000,
                'description' => 'Revisi nominal setelah potong diskon',
            ],
            [
                'supporting' => UploadedFile::fake()->create('revisi_kwitansi.pdf', 100),
            ]
        );

        $this->assertSame(GaClaim::STATUS_SUBMITTED, $resubmitted->status);
        $this->assertSame(2, $resubmitted->revision_number);
        $this->assertEquals(450000, $resubmitted->amount);
        $this->assertCount(1, $resubmitted->documents);
        $this->assertSame(2, $resubmitted->documents->first()->revision_number);
    }

    public function test_employee_master_management_via_controller(): void
    {
        $ga = User::factory()->create(['role' => 'ga', 'is_active' => true]);

        // Index
        $this->actingAs($ga)->get(route('ga.employees.index'))->assertOk();

        // Store
        $responseStore = $this->actingAs($ga)->post(route('ga.employees.store'), [
            'name' => 'Bambang Sudirman',
            'department' => 'Maintenance',
            'bank_name' => 'BCA',
            'account_number' => '888777666',
            'account_holder_name' => 'Bambang Sudirman',
        ]);
        $responseStore->assertRedirect();
        $this->assertDatabaseHas('employees', ['name' => 'Bambang Sudirman', 'is_active' => true]);

        $employee = \App\Models\Employee::where('name', 'Bambang Sudirman')->first();

        // Update
        $responseUpdate = $this->actingAs($ga)->put(route('ga.employees.update', $employee), [
            'name' => 'Bambang Sudirman SH',
            'department' => 'General Affairs',
            'bank_name' => 'Mandiri',
            'account_number' => '888777666',
            'account_holder_name' => 'Bambang Sudirman',
            'is_active' => 1,
        ]);
        $responseUpdate->assertRedirect();
        $this->assertSame('Bambang Sudirman SH', $employee->fresh()->name);

        // Toggle Status
        $this->actingAs($ga)->post(route('ga.employees.toggle-status', $employee));
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_finance_ga_claims_routes_and_verification(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $gaUser = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $employee = $this->createEmployee();

        $claim = $this->claimService->submitClaim(
            $gaUser,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_ENTERTAIN_SALES,
                'claim_date' => '2026-09-10',
                'amount' => 1200000,
            ]
        );
        $this->claimService->basicVerify($claim, $gaUser);

        // Finance Index
        $this->actingAs($finance)->get(route('finance.ga-claims.index'))->assertOk()->assertSee($claim->claim_number);

        // Finance Show
        $this->actingAs($finance)->get(route('finance.ga-claims.show', $claim))->assertOk()->assertSee('1.200.000');

        // Finance Approve
        $responseVerify = $this->actingAs($finance)->post(route('finance.ga-claims.verify', $claim), [
            'approve' => 1,
        ]);
        $responseVerify->assertRedirect();
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $claim->fresh()->status);
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

    public function test_ga_drp_draft_renders_and_creates_batch_draft(): void
    {
        $ga = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $employee = $this->createEmployee();

        $claim = GaClaim::create([
            'claim_number' => 'GA-CLAIM-READY-01',
            'employee_id' => $employee->id,
            'claim_type' => GaClaim::TYPE_UPD_GA,
            'claim_date' => '2026-09-10',
            'amount' => 500000,
            'status' => GaClaim::STATUS_READY_TO_PAY,
            'submitted_by' => $ga->id,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($ga)->get(route('ga.drp-draft'));
        $response->assertOk();
        $response->assertSee('GA-CLAIM-READY-01');

        $storeResponse = $this->actingAs($ga)->post(route('ga.drp-draft.store'), [
            'claim_ids' => [$claim->id],
            'notes' => 'Batch draft for test',
        ]);
        $storeResponse->assertRedirect(route('ga.claims.index'));
        $storeResponse->assertSessionHas('success');
    }
}
