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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GaClaimCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function data(string $type): array
    {
        $employee = Employee::create([
            'name' => 'Canonical Employee', 'department' => 'GA', 'bank_name' => 'Mandiri',
            'account_number' => '123456', 'account_holder_name' => 'Canonical Employee', 'is_active' => true,
        ]);

        return ['employee_id' => $employee->id, 'claim_type' => $type, 'claim_date' => '2026-10-07', 'amount' => '250000'];
    }

    public function test_entertainment_requires_supporting_document_in_service_and_http(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $data = $this->data(GaClaim::TYPE_ENTERTAINMENT);
        $this->actingAs($ga)->post(route('ga.claims.store'), $data)->assertSessionHasErrors('supporting');
        $this->assertDatabaseCount('ga_claims', 0);

        $this->expectException(ValidationException::class);
        app(GaClaimService::class)->submitClaim($ga, $data);
    }

    public function test_entertainment_document_is_stored_privately_and_non_entertainment_remains_optional(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $service = app(GaClaimService::class);
        $claim = $service->submitClaim($ga, $this->data(GaClaim::TYPE_ENTERTAINMENT), [
            'supporting' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ]);
        $this->assertSame('supporting', $claim->documents->sole()->document_type);
        Storage::disk('private')->assertExists($claim->documents->sole()->file_path);
        foreach ([GaClaim::TYPE_BUSINESS_TRAVEL, GaClaim::TYPE_REIMBURSE_CLAIM] as $type) {
            $this->assertSame($type, $service->submitClaim($ga, $this->data($type))->claim_type);
        }
    }

    public function test_historical_entertainment_can_be_verified_but_explicit_resubmission_requires_new_revision_document(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $finance = User::factory()->create(['role' => 'finance']);
        $claim = GaClaim::create([...$this->data(GaClaim::TYPE_ENTERTAINMENT),
            'claim_number' => 'CLM-HISTORICAL', 'status' => GaClaim::STATUS_SUBMITTED,
            'submitted_by' => $ga->id, 'submitted_at' => now(),
        ]);
        $service = app(GaClaimService::class);
        $service->basicVerify($claim, $ga);
        $ready = app(GaVerificationService::class)->financeVerify($claim, $finance, true);
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $ready->status);
        $this->assertCount(0, $ready->documents);
        $claim->update(['status' => GaClaim::STATUS_NEED_REVISION]);
        $data = ['claim_type' => GaClaim::TYPE_ENTERTAINMENT, 'claim_date' => '2026-10-07', 'amount' => '250000'];
        try {
            $service->resubmitClaim($ga, $claim, $data);
            $this->fail('Entertainment resubmission without document must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supporting', $e->errors());
        }
        $this->assertSame(GaClaim::STATUS_NEED_REVISION, $claim->fresh()->status);
        $revised = $service->resubmitClaim($ga, $claim, $data, [
            'supporting' => UploadedFile::fake()->create('revised.pdf', 20, 'application/pdf'),
        ]);
        $this->assertSame(2, $revised->documents->sole()->revision_number);
        $this->assertSame(GaClaim::STATUS_SUBMITTED, $revised->status);
    }

    public function test_legacy_types_are_rejected_at_domain_boundary(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $this->expectException(ValidationException::class);
        app(GaClaimService::class)->submitClaim($ga, $this->data('UPD GA'));
    }

    public function test_canonical_categories_and_supporting_rule_have_labels_in_both_locales(): void
    {
        $this->assertSame(['Entertainment', 'Business Travel', 'Reimburse/Claim'], GaClaim::CLAIM_TYPES);
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            foreach (GaClaim::CLAIM_TYPES as $type) {
                $this->assertNotEmpty(GaClaim::claimTypeLabel($type));
                $this->assertStringNotContainsString('ga.types.', GaClaim::claimTypeLabel($type));
            }
            $this->assertNotSame('ga.validation.entertainment_supporting', __('ga.validation.entertainment_supporting'));
        }
    }
}
