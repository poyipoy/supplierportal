<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\Supplier;
use App\Models\SupplierMasterDocument;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierRegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_title' => 'PT',
            'company_name' => 'PT Baja Bersama Abadi',
            'address' => 'Jl. Industri No. 88, Cikarang',
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

    public function test_privilege_tampering_is_prevented(): void
    {
        $tamperedPayload = $this->validPayload([
            'role' => 'admin',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            'supplier_scopes' => ['import', 'local'],
            'approved_by' => 1,
            'reviewed_by' => 1,
        ]);

        $this->post(route('supplier.register.store'), $tamperedPayload);

        $user = User::where('email', 'procurement@bajabersama.com')->first();
        $this->assertNotNull($user);

        // Security assertions: privileges must not be altered by user input
        $this->assertSame('supplier', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertSame(User::ACCOUNT_STATUS_PENDING, $user->account_status);
        $this->assertSame(0, $user->supplierScopes()->count());
    }

    public function test_pending_revision_and_rejected_users_cannot_login(): void
    {
        $password = 'CorrectPassword123!';

        // 1. Pending Supplier
        $pendingUser = User::factory()->create([
            'role' => 'supplier',
            'password' => Hash::make($password),
            'is_active' => false,
            'account_status' => User::ACCOUNT_STATUS_PENDING,
        ]);

        $pendingLogin = $this->post(route('login.store'), [
            'email' => $pendingUser->email,
            'password' => $password,
        ]);
        $pendingLogin->assertSessionHasErrors('email');
        $this->assertFalse(auth()->check(), 'Pending account must not be able to log in');

        // 2. Revision Supplier
        $revisionUser = User::factory()->create([
            'role' => 'supplier',
            'password' => Hash::make($password),
            'is_active' => false,
            'account_status' => User::ACCOUNT_STATUS_REVISION,
        ]);

        $revisionLogin = $this->post(route('login.store'), [
            'email' => $revisionUser->email,
            'password' => $password,
        ]);
        $revisionLogin->assertSessionHasErrors('email');
        $this->assertFalse(auth()->check(), 'Revision account must not be able to log in');

        // 3. Rejected Supplier
        $rejectedUser = User::factory()->create([
            'role' => 'supplier',
            'password' => Hash::make($password),
            'is_active' => false,
            'account_status' => User::ACCOUNT_STATUS_REJECTED,
        ]);

        $rejectedLogin = $this->post(route('login.store'), [
            'email' => $rejectedUser->email,
            'password' => $password,
        ]);
        $rejectedLogin->assertSessionHasErrors('email');
        $this->assertFalse(auth()->check(), 'Rejected account must not be able to log in');

        // 4. Active Supplier
        $activeUser = User::factory()->create([
            'role' => 'supplier',
            'password' => Hash::make($password),
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $activeLogin = $this->post(route('login.store'), [
            'email' => $activeUser->email,
            'password' => $password,
        ]);
        $this->assertTrue(auth()->check(), 'Active account must be able to log in');
    }

    public function test_duplicate_email_nib_and_npwp_are_blocked_against_active_or_pending_suppliers(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $service->submitInitialRegistration(
            data: $this->validPayload(),
            files: [
                'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
            ],
        );

        // Attempt 1: Duplicate email
        $dupEmail = $this->post(route('supplier.register.store'), $this->validPayload([
            'nib' => '9999999999999',
            'npwp' => '99.999.999.9-999.000',
        ]));
        $dupEmail->assertSessionHasErrors('email');

        // Attempt 2: Duplicate NIB
        $dupNib = $this->post(route('supplier.register.store'), $this->validPayload([
            'email' => 'different@company.com',
            'npwp' => '99.999.999.9-999.000',
        ]));
        $dupNib->assertSessionHasErrors('nib');

        // Attempt 3: Duplicate NPWP
        $dupNpwp = $this->post(route('supplier.register.store'), $this->validPayload([
            'email' => 'different@company.com',
            'nib' => '9999999999999',
        ]));
        $dupNpwp->assertSessionHasErrors('npwp');
    }

    public function test_registration_access_session_cannot_access_portal_routes(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validPayload(),
            files: [
                'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
            ],
        );

        // Authenticate into registration session
        $this->post(route('supplier.registration.access'), [
            'reference' => $result['reference'],
            'access_key' => $result['access_key'],
        ]);

        // Attempt to access authenticated portal dashboard
        $response = $this->get('/dashboard');
        $response->assertRedirect(route('login'));
    }

    public function test_document_download_idor_is_prevented(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);

        // Supplier 1
        $res1 = $service->submitInitialRegistration(
            data: $this->validPayload(['email' => 'supplier1@test.com', 'nib' => '1111111111111', 'npwp' => '11.111.111.1-111.000']),
            files: [
                'nib_file' => UploadedFile::fake()->create('nib1.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp1.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr1.pdf', 600, 'application/pdf'),
            ],
        );

        // Supplier 2
        $res2 = $service->submitInitialRegistration(
            data: $this->validPayload(['email' => 'supplier2@test.com', 'nib' => '2222222222222', 'npwp' => '22.222.222.2-222.000']),
            files: [
                'nib_file' => UploadedFile::fake()->create('nib2.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp2.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr2.pdf', 600, 'application/pdf'),
            ],
        );

        $docSupplier2 = SupplierMasterDocument::where('supplier_id', $res2['attempt']->user_id)->first();

        // Applicant 1 attempts to download document belonging to Supplier 2
        $this->post(route('supplier.registration.access'), [
            'reference' => $res1['reference'],
            'access_key' => $res1['access_key'],
        ]);

        $response = $this->get(route('supplier.registration.document.download', $docSupplier2->hash));
        $response->assertForbidden();
    }
}
