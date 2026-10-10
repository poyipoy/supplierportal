<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierRegistrationAudit;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class SupplierRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function validRegistrationPayload(array $overrides = []): array
    {
        return array_merge([
            'company_title' => 'PT',
            'company_name' => 'PT Baja Bersama Abadi',
            'address' => 'Jl. Industri No. 88, Cikarang, Jawa Barat',
            'phone' => '021-89898989',
            'category' => 'Steel Supplier',
            'is_pkp' => '1',
            'nib' => '1234567890123',
            'npwp' => '01.234.567.8-412.000',
            'pic_name' => 'Budi Santoso',
            'pic_email' => 'budi@bajabersama.com',
            'pic_phone' => '081234567890',
            'bank_name' => 'Bank Mandiri',
            'account_number' => '1230009876543',
            'account_holder_name' => 'PT Baja Bersama Abadi',
            'email' => 'procurement@bajabersama.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
            'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
            'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            'questionnaire' => self::expectedAnswers(),
        ], $overrides);
    }

    /** Answers that match every expected value (nothing flagged). */
    private static function expectedAnswers(): array
    {
        return SupplierComplianceQuestionnaire::QUESTIONS;
    }

    public function test_registration_notifications_render_each_reviewer_locale_and_preserve_scope_values(): void
    {
        Queue::fake();
        $english = User::factory()->create(['role' => 'admin']);
        $indonesian = User::factory()->create(['role' => 'finance']);
        $indonesian->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        $payload = $this->validRegistrationPayload();
        app()->setLocale('id');
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration($payload, [
            'nib_file' => $payload['nib_file'], 'npwp_file' => $payload['npwp_file'], 'sknr_file' => $payload['sknr_file'],
        ]);
        $submitted = $english->notifications()->where('data->event', 'supplier_registration.submitted')->sole();
        $this->assertSame('New Supplier Registration', $submitted->data['title']);
        $this->assertSame('New Supplier Registration', $indonesian->notifications()->where('data->event', 'supplier_registration.submitted')->sole()->data['title']);
        $this->assertStringContainsString($payload['company_name'], $submitted->data['message']);
        $this->assertStringContainsString($result['reference'], $submitted->data['message']);
        $this->assertSame('id', app()->getLocale());
        $service->approveRegistration($result['attempt'], $english, ['import', 'local']);
        $en = $english->notifications()->where('data->event', 'supplier_registration.approved')->sole();
        $id = $indonesian->notifications()->where('data->event', 'supplier_registration.approved')->sole();
        $this->assertStringContainsString('Material Procurement, Local Supplier', $en->data['message']);
        $this->assertStringContainsString('Pengadaan Material, Local Supplier', $id->data['message']);
        $this->assertEqualsCanonicalizing(['import', 'local'], $result['attempt']->user->supplierScopes()->pluck('scope')->all());
        $this->assertSame('New Supplier Registration', $submitted->fresh()->data['title']);
    }

    public function test_public_supplier_can_submit_registration_successfully(): void
    {
        $payload = $this->validRegistrationPayload();

        $response = $this->post(route('supplier.register.store'), $payload);

        $response->assertRedirect(route('supplier.registration.success'));
        $this->assertFalse(auth()->check(), 'Registration must not automatically authenticate the supplier');

        // Verify User record
        $user = User::where('email', 'procurement@bajabersama.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('supplier', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertSame(User::ACCOUNT_STATUS_PENDING, $user->account_status);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertSame(0, $user->supplierScopes()->count(), 'New registration must have no scopes assigned');

        // Verify Supplier record & fingerprints
        $supplier = Supplier::where('user_id', $user->id)->first();
        $this->assertNotNull($supplier);
        $this->assertSame('PT', $supplier->company_title);
        $this->assertSame('PT Baja Bersama Abadi', $supplier->company_name);
        $this->assertNotNull($supplier->nib_fingerprint);
        $this->assertNotNull($supplier->tax_identity_fingerprint);

        // Verify Bank Account
        $bankAccount = SupplierBankAccount::where('supplier_id', $user->id)->first();
        $this->assertNotNull($bankAccount);
        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $bankAccount->status);

        // Verify Master Documents
        $docs = SupplierMasterDocument::where('supplier_id', $user->id)->get();
        $this->assertCount(3, $docs);
        $sknrDoc = $docs->firstWhere('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING);
        $this->assertNotNull($sknrDoc);
        $this->assertSame($bankAccount->id, $sknrDoc->supplier_bank_account_id);
        Storage::disk('private')->assertExists($sknrDoc->file_path);

        // Verify Registration Attempt
        $attempt = SupplierRegistrationAttempt::where('user_id', $user->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame(SupplierRegistrationAttempt::STATUS_PENDING, $attempt->status);
        $this->assertArrayNotHasKey('password', $attempt->snapshot);

        // Verify Registration Access Credential
        $access = SupplierRegistrationAccess::where('user_id', $user->id)->first();
        $this->assertNotNull($access);
        $this->assertStringStartsWith('REG-', $access->registration_reference);
        $this->assertNotNull($access->token_hash);
        $this->assertNull($access->revoked_at);

        // Verify Audit Trail
        $audit = SupplierRegistrationAudit::where('user_id', $user->id)->where('event', 'registration_submitted')->first();
        $this->assertNotNull($audit);
    }

    public function test_registration_validation_fails_on_missing_required_fields(): void
    {
        $response = $this->post(route('supplier.register.store'), []);

        $response->assertSessionHasErrors([
            'company_name',
            'address',
            'phone',
            'nib',
            'npwp',
            'pic_name',
            'pic_email',
            'pic_phone',
            'bank_name',
            'account_number',
            'account_holder_name',
            'email',
            'password',
            'nib_file',
            'npwp_file',
            'sknr_file',
        ]);
    }

    public function test_registration_enforces_5mb_max_file_size_and_mimes(): void
    {
        $payload = $this->validRegistrationPayload([
            'nib_file' => NativeFileFixtures::upload('huge.pdf', 6000), // > 5MB
            'npwp_file' => UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload'),
        ]);

        $response = $this->post(route('supplier.register.store'), $payload);

        $response->assertSessionHasErrors(['nib_file', 'npwp_file']);
    }

    public function test_applicant_can_access_status_using_reference_and_key(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validRegistrationPayload(),
            files: [
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            ],
        );

        $reference = $result['reference'];
        $accessKey = $result['access_key'];

        // Access via authentication endpoint
        $authResponse = $this->post(route('supplier.registration.access'), [
            'reference' => $reference,
            'access_key' => $accessKey,
        ]);

        $authResponse->assertRedirect(route('supplier.registration.status'));
        $this->assertFalse(auth()->check(), 'Registration access session must not log user in as Laravel auth user');

        // View status page
        $statusResponse = $this->get(route('supplier.registration.status'));
        $statusResponse->assertOk();
        $statusResponse->assertSee('PT Baja Bersama Abadi');
        $statusResponse->assertSee(__('status.registration.pending', [], 'en'));
        $this->assertDatabaseHas('supplier_registration_attempts', ['id' => $result['attempt']->id, 'status' => 'PENDING']);
        $statusResponse->assertSee($reference);
    }

    public function test_revision_and_resubmission_lifecycle(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validRegistrationPayload(),
            files: [
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            ],
        );

        $attempt = $result['attempt'];
        $reviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        // Reviewer requests revision
        $service->requestRevision($attempt, $reviewer, 'Please clarify company telephone and update SKNR.');

        $attempt->refresh();
        $this->assertSame(SupplierRegistrationAttempt::STATUS_REVISION, $attempt->status);
        $this->assertSame(User::ACCOUNT_STATUS_REVISION, $attempt->user->account_status);

        // Applicant authenticates into registration session
        $this->post(route('supplier.registration.access'), [
            'reference' => $result['reference'],
            'access_key' => $result['access_key'],
        ]);

        // View revision edit page
        $editResponse = $this->get(route('supplier.registration.edit'));
        $editResponse->assertOk();
        $editResponse->assertSee('Please clarify company telephone and update SKNR.');

        // Resubmit with revised telephone
        $resubmitPayload = array_merge($this->validRegistrationPayload([
            'phone' => '021-99998888',
            'revision_notes' => 'Updated telephone to direct corporate line.',
        ]), [
            'nib_file' => null,
            'npwp_file' => null,
            'sknr_file' => null, // preserve existing files
        ]);

        $resubmitResponse = $this->post(route('supplier.registration.resubmit'), $resubmitPayload);
        $resubmitResponse->assertRedirect(route('supplier.registration.status'));

        // Check new attempt
        $user = $attempt->user->fresh();
        $this->assertSame(User::ACCOUNT_STATUS_PENDING, $user->account_status);
        $this->assertSame(2, $user->registrationAttempts()->count());

        $newAttempt = $user->registrationAttempts()->orderByDesc('attempt_number')->first();
        $this->assertSame(2, $newAttempt->attempt_number);
        $this->assertSame(SupplierRegistrationAttempt::STATUS_PENDING, $newAttempt->status);
        $this->assertSame('021-99998888', $user->supplier->fresh()->phone);
    }

    public function test_rejected_applicant_can_re_register_using_same_identifiers(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validRegistrationPayload(),
            files: [
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            ],
        );

        $reviewer = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service->rejectRegistration($result['attempt'], $reviewer, 'Business scope does not match ADASI requirements.');

        $user = $result['attempt']->user->fresh();
        $this->assertSame(User::ACCOUNT_STATUS_REJECTED, $user->account_status);
        $this->assertFalse((bool) $user->is_active);
        $this->assertNull($user->supplier->nib_fingerprint, 'Fingerprints must be released upon rejection');
        $this->assertNull($user->supplier->tax_identity_fingerprint);

        // Same supplier applies again using identical email, NIB, and NPWP
        $reRegisterPayload = $this->validRegistrationPayload([
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $secondResponse = $this->post(route('supplier.register.store'), $reRegisterPayload);
        $secondResponse->assertRedirect(route('supplier.registration.success'));

        $user->refresh();
        $this->assertSame(User::ACCOUNT_STATUS_PENDING, $user->account_status);
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertSame(2, $user->registrationAttempts()->count(), 'New registration attempt recorded for reused identity');
    }

    public function test_public_supplier_registration_form_renders_progressive_wizard_components(): void
    {
        $response = $this->get(route('supplier.register'));

        $response->assertOk();
        $response->assertSee('supplierRegistrationWizard');
        $response->assertSee(__('registration.account_profile', [], 'en'));
        $response->assertSee(__('registration.legal_identification', [], 'en'));
        $response->assertSee(__('registration.js.heading3', [], 'en'));
        $response->assertSee(__('registration.review_heading', [], 'en'));
        $response->assertSee(__('registration.declaration_title', [], 'en'));
        $response->assertSee('bank_select');
        $response->assertSee('other_bank_name');
        $response->assertSee('nib_file');
        $response->assertSee('npwp_file');
        $response->assertSee('sknr_file');
        $response->assertSee('company_profile_file');
        $response->assertSee('data-async-submit', false);
        $response->assertSee(__('registration.questionnaire.title', [], 'en'));
        foreach (SupplierComplianceQuestionnaire::keys() as $key) {
            $response->assertSee('name="questionnaire['.$key.']"', false);
            $response->assertSee(__('registration.questionnaire.questions.'.$key, [], 'en'));
        }
        $response->assertSee(__('registration.email', [], 'en'));
        $response->assertDontSee('Official Company Email Address');
    }

    public function test_registration_password_requires_min_8_mixed_case_number_and_symbol(): void
    {
        foreach (['abcdef1!', 'ABCDEF1!', 'Abcdefg!', 'Abcdefg1', 'Abc1!xy'] as $weak) {
            $this->post(route('supplier.register.store'), $this->validRegistrationPayload([
                'password' => $weak,
                'password_confirmation' => $weak,
            ]))->assertSessionHasErrors('password');
        }
        $this->assertSame(0, User::where('email', 'procurement@bajabersama.com')->count());

        // Exactly 8 characters with upper, lower, number and symbol is accepted (internal default stays min 12).
        $this->post(route('supplier.register.store'), $this->validRegistrationPayload([
            'password' => 'Abcdef1!',
            'password_confirmation' => 'Abcdef1!',
        ]))->assertRedirect(route('supplier.registration.success'));

        $this->assertTrue(Hash::check('Abcdef1!', User::where('email', 'procurement@bajabersama.com')->sole()->password));
    }

    public function test_questionnaire_is_required_for_every_question(): void
    {
        $missing = $this->validRegistrationPayload(['questionnaire' => ['quality_standard' => 'yes', 'msds' => 'maybe']]);

        $this->post(route('supplier.register.store'), $missing)->assertSessionHasErrors([
            'questionnaire.quality_pic',
            'questionnaire.msds',
            'questionnaire.product_safe',
            'questionnaire.child_labor',
            'questionnaire.minimum_wage',
        ]);
        $this->assertSame(0, User::count());
    }

    public function test_risky_questionnaire_answers_do_not_block_and_are_stored_on_supplier_and_snapshot(): void
    {
        $answers = array_merge(self::expectedAnswers(), [
            'child_labor' => SupplierComplianceQuestionnaire::YES,
            'minimum_wage' => SupplierComplianceQuestionnaire::NO,
        ]);

        $this->post(route('supplier.register.store'), $this->validRegistrationPayload([
            'questionnaire' => $answers + ['injected_key' => 'yes'],
        ]))->assertRedirect(route('supplier.registration.success'));

        $user = User::where('email', 'procurement@bajabersama.com')->sole();
        $stored = $user->supplier->compliance_questionnaire;
        $this->assertSame(SupplierComplianceQuestionnaire::VERSION, $stored['version']);
        // MySQL JSON columns do not preserve key order, so compare key/value pairs only.
        $this->assertEquals($answers, $stored['answers']);
        $this->assertArrayNotHasKey('injected_key', $stored['answers']);
        $this->assertNotEmpty($stored['answered_at']);
        $this->assertSame($answers, SupplierComplianceQuestionnaire::answersFrom($stored), 'Reading back restores canonical order');

        $attempt = $user->registrationAttempts()->sole();
        $this->assertEquals($answers, $attempt->snapshot['questionnaire']);
        $this->assertSame(['child_labor', 'minimum_wage'], SupplierComplianceQuestionnaire::flagged($attempt->snapshot['questionnaire']));
    }

    public function test_company_profile_is_optional_stored_with_its_own_type_and_limited_to_10mb(): void
    {
        $this->post(route('supplier.register.store'), $this->validRegistrationPayload([
            'company_profile_file' => UploadedFile::fake()->create('profile.pdf', 10241, 'application/pdf'),
        ]))->assertSessionHasErrors('company_profile_file');

        $this->post(route('supplier.register.store'), $this->validRegistrationPayload([
            'company_profile_file' => NativeFileFixtures::upload('profile.pdf', 9000),
        ]))->assertRedirect(route('supplier.registration.success'));

        $user = User::where('email', 'procurement@bajabersama.com')->sole();
        $profile = SupplierMasterDocument::where('supplier_id', $user->id)
            ->where('document_type', SupplierMasterDocument::TYPE_COMPANY_PROFILE)
            ->sole();
        $this->assertSame('profile.pdf', $profile->original_filename);
        Storage::disk('private')->assertExists($profile->file_path);
        $this->assertArrayHasKey(SupplierMasterDocument::TYPE_COMPANY_PROFILE, $user->registrationAttempts()->sole()->snapshot['documents']);
    }

    public function test_async_submit_with_invalid_data_returns_422_json_and_stores_nothing(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post(route('supplier.register.store'), $this->validRegistrationPayload(['nib' => '', 'questionnaire' => []]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['nib', 'questionnaire']);

        $this->assertSame(0, User::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_async_submit_returns_same_origin_redirect_and_keeps_access_key_out_of_json(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post(route('supplier.register.store'), $this->validRegistrationPayload());

        $response->assertOk()->assertExactJson(['redirect' => route('supplier.registration.success')]);

        $accessKey = session('registration_access_key');
        $reference = session('registration_reference');
        $this->assertIsString($accessKey);
        $this->assertStringNotContainsString($accessKey, $response->getContent());

        // The one-time credentials are still delivered through the flashed session on the success page.
        $this->get(route('supplier.registration.success'))->assertOk()->assertSee($reference)->assertSee($accessKey);
    }

    public function test_async_resubmit_updates_questionnaire_and_company_profile(): void
    {
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validRegistrationPayload(),
            files: [
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            ],
        );
        $reviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $service->requestRevision($result['attempt'], $reviewer, 'Please attach your company profile.');

        $this->post(route('supplier.registration.access'), [
            'reference' => $result['reference'],
            'access_key' => $result['access_key'],
        ]);

        $edit = $this->get(route('supplier.registration.edit'))->assertOk();
        $edit->assertSee('data-async-submit', false);
        $edit->assertSee('name="questionnaire[child_labor]"', false);
        $edit->assertSee('company_profile_file');

        $revisedAnswers = array_merge(self::expectedAnswers(), ['msds' => SupplierComplianceQuestionnaire::NO]);
        $payload = array_merge($this->validRegistrationPayload(['questionnaire' => $revisedAnswers]), [
            'nib_file' => null,
            'npwp_file' => null,
            'sknr_file' => null,
            'company_profile_file' => NativeFileFixtures::upload('company-profile.pdf', 2000),
        ]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post(route('supplier.registration.resubmit'), $payload)
            ->assertOk()
            ->assertExactJson(['redirect' => route('supplier.registration.status')]);

        $user = $result['attempt']->user->fresh();
        $this->assertEquals($revisedAnswers, $user->supplier->compliance_questionnaire['answers']);
        $newAttempt = $user->registrationAttempts()->orderByDesc('attempt_number')->first();
        $this->assertSame(2, $newAttempt->attempt_number);
        $this->assertEquals($revisedAnswers, $newAttempt->snapshot['questionnaire']);
        $this->assertArrayHasKey(SupplierMasterDocument::TYPE_COMPANY_PROFILE, $newAttempt->snapshot['documents']);
        $this->assertArrayHasKey(SupplierMasterDocument::TYPE_NIB, $newAttempt->snapshot['documents'], 'Existing documents are preserved');
    }

    public function test_reviewer_sees_questionnaire_answers_with_attention_flags(): void
    {
        $this->post(route('supplier.register.store'), $this->validRegistrationPayload([
            'questionnaire' => array_merge(self::expectedAnswers(), ['child_labor' => SupplierComplianceQuestionnaire::YES]),
        ]))->assertRedirect(route('supplier.registration.success'));

        $attempt = User::where('email', 'procurement@bajabersama.com')->sole()->registrationAttempts()->sole();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('supplier-registrations.show', $attempt->hash))
            ->assertOk()
            ->assertSee(__('local_procurement.registration.questionnaire', [], 'en'))
            ->assertSee(__('registration.questionnaire.questions.child_labor', [], 'en'))
            ->assertSee(__('registration.questionnaire.needs_attention', [], 'en'))
            ->assertSee(trans_choice('local_procurement.registration.questionnaire_flagged', 1, ['count' => 1], 'en'));
    }
}
