<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierMasterDocument;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class SupplierRegistrationPkpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function payload(bool $isPkp = true): array
    {
        return [
            'company_name' => 'PKP Test Company', 'address' => 'Jakarta', 'phone' => '0211234567',
            'nib' => '1234567890123', 'npwp' => '01.234.567.8-412.000', 'is_pkp' => $isPkp ? '1' : '0',
            'pic_name' => 'Contact', 'pic_email' => 'contact@example.test', 'pic_phone' => '0812345678',
            'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder_name' => 'PKP Test Company',
            'email' => 'pkp@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'questionnaire' => SupplierComplianceQuestionnaire::QUESTIONS,
        ];
    }

    private function files(bool $withSppkp = false): array
    {
        $files = [
            'nib_file' => NativeFileFixtures::upload('nib.pdf', 10),
            'npwp_file' => NativeFileFixtures::upload('npwp.pdf', 10),
            'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 10),
        ];
        if ($withSppkp) {
            $files['sppkp_file'] = NativeFileFixtures::upload('sppkp.pdf', 10);
        }

        return $files;
    }

    private function revision(bool $isPkp, bool $withSppkp): array
    {
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration($this->payload($isPkp), $this->files($withSppkp));
        $reviewer = User::factory()->create(['role' => 'purchasing']);
        $service->requestRevision($result['attempt'], $reviewer, 'Please review your tax documents.');
        $this->post(route('supplier.registration.access'), [
            'reference' => $result['reference'], 'access_key' => $result['access_key'],
        ])->assertRedirect(route('supplier.registration.status'));

        return [$result, $reviewer];
    }

    public function test_pkp_http_registration_requires_sppkp_before_persisting(): void
    {
        $this->postJson(route('supplier.register.store'), $this->payload() + $this->files())
            ->assertUnprocessable()->assertJsonValidationErrors('sppkp_file');
        $this->assertDatabaseCount('supplier_registration_attempts', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_direct_pkp_registration_cannot_bypass_required_sppkp(): void
    {
        try {
            app(SupplierRegistrationService::class)->submitInitialRegistration($this->payload(), $this->files());
            $this->fail('PKP registration without SPPKP must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sppkp_file', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_pkp_registration_accepts_no_sppkp(): void
    {
        $this->postJson(route('supplier.register.store'), $this->payload(false) + $this->files())->assertOk();
        $this->assertDatabaseHas('suppliers', ['is_pkp' => false]);
    }

    public function test_pkp_registration_stores_sppkp_in_its_snapshot(): void
    {
        $this->postJson(route('supplier.register.store'), $this->payload() + $this->files(true))->assertOk();
        $user = User::where('email', 'pkp@example.test')->sole();
        $document = $user->supplierMasterDocuments()->where('document_type', SupplierMasterDocument::TYPE_SPPKP)->sole();
        $this->assertSame($document->id, $user->registrationAttempts()->sole()->submission_snapshot['documents']['SPPKP']['document_id']);
    }

    public function test_revision_reuses_readable_current_cycle_sppkp_without_uploading_again(): void
    {
        [$result] = $this->revision(true, true);
        $documentId = $result['attempt']->submission_snapshot['documents']['SPPKP']['document_id'];
        $this->postJson(route('supplier.registration.resubmit'), $this->payload())->assertOk();
        $latest = $result['attempt']->user->registrationAttempts()->latest('attempt_number')->first();
        $this->assertSame($documentId, $latest->submission_snapshot['documents']['SPPKP']['document_id']);
    }

    public function test_revision_changing_to_pkp_requires_sppkp_in_http_and_service(): void
    {
        [$result] = $this->revision(false, false);
        $this->postJson(route('supplier.registration.resubmit'), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('sppkp_file');
        try {
            app(SupplierRegistrationService::class)->resubmitRevision($result['attempt']->user, $this->payload(), []);
            $this->fail('A direct PKP revision without SPPKP must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sppkp_file', $exception->errors());
        }
        $this->assertDatabaseHas('suppliers', ['is_pkp' => false]);
        $this->assertSame(1, $result['attempt']->user->registrationAttempts()->count());
    }

    public function test_unreadable_retained_sppkp_does_not_satisfy_pkp_revision(): void
    {
        [$result] = $this->revision(true, true);
        $document = $result['attempt']->user->supplierMasterDocuments()->where('document_type', 'SPPKP')->sole();
        Storage::disk('private')->delete($document->file_path);
        $this->postJson(route('supplier.registration.resubmit'), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('sppkp_file');
        try {
            app(SupplierRegistrationService::class)->resubmitRevision($result['attempt']->user, $this->payload(), []);
            $this->fail('Unreadable SPPKP must not be retained by a direct revision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sppkp_file', $exception->errors());
        }
        $this->postJson(route('supplier.registration.resubmit'), $this->payload() + [
            'sppkp_file' => NativeFileFixtures::upload('replacement-sppkp.pdf', 10),
        ])->assertOk();
    }

    public function test_another_suppliers_sppkp_does_not_satisfy_revision(): void
    {
        [$result] = $this->revision(true, true);
        $other = User::factory()->create(['role' => 'supplier']);
        $result['attempt']->user->supplierMasterDocuments()->where('document_type', 'SPPKP')->update(['supplier_id' => $other->id]);
        $this->postJson(route('supplier.registration.resubmit'), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('sppkp_file');
    }

    public function test_sppkp_from_a_rejected_cycle_does_not_satisfy_a_new_cycle(): void
    {
        $service = app(SupplierRegistrationService::class);
        $old = $service->submitInitialRegistration($this->payload(), $this->files(true));
        $reviewer = User::factory()->create(['role' => 'purchasing']);
        $service->rejectRegistration($old['attempt'], $reviewer, 'Register again.');
        [$current] = $this->revision(false, false);
        $this->postJson(route('supplier.registration.resubmit'), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('sppkp_file');
        $this->assertSame(2, $current['attempt']->user->registrationAttempts()->count());
    }

    public function test_shared_upload_dynamic_requirement_preserves_one_file_input_and_static_callers(): void
    {
        $dynamic = Blade::render('<x-ui.file-upload name="sppkp_file" required-when="formData.is_pkp" :required="true" />');
        $this->assertSame(1, substr_count($dynamic, 'type="file"'));
        $this->assertStringContainsString(':required="(formData.is_pkp) && existingFiles.length === 0"', html_entity_decode($dynamic));
        $static = Blade::render('<x-ui.file-upload name="plain" :required="true" />');
        $this->assertStringNotContainsString(':required=', $static);
        $this->assertStringContainsString('required', $static);
    }
}
