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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
            'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
            'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
        ], $overrides);
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
            'nib_file' => UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'), // > 5MB
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
                'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
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
        $statusResponse->assertSee('PENDING');
        $statusResponse->assertSee($reference);
    }

    public function test_revision_and_resubmission_lifecycle(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validRegistrationPayload(),
            files: [
                'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
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
                'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
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
        $response->assertSee('Akun Portal & Profil Perusahaan', false);
        $response->assertSee('Identitas Legalitas & Perpajakan', false);
        $response->assertSee('Berkas Dokumen Verifikasi Rekanan', false);
        $response->assertSee('Tinjau Ringkasan Pendaftaran (Pre-flight Review)', false);
        $response->assertSee('Pernyataan Kebenaran Data');
        $response->assertSee('bank_select');
        $response->assertSee('other_bank_name');
        $response->assertSee('nib_file');
        $response->assertSee('npwp_file');
        $response->assertSee('sknr_file');
    }
}
