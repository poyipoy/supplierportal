<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierRegistrationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function createPendingAttempt(): SupplierRegistrationAttempt
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: [
                'company_title' => 'PT',
                'company_name' => 'PT Concurrency Test',
                'address' => 'Jl. Uji Coba No. 1',
                'phone' => '021-112233',
                'category' => 'Supplier',
                'nib' => '5555555555555',
                'npwp' => '55.555.555.5-555.000',
                'pic_name' => 'Tester',
                'pic_email' => 'tester@concurrency.com',
                'pic_phone' => '085555555555',
                'bank_name' => 'BCA',
                'account_number' => '55555555',
                'account_holder_name' => 'PT Concurrency Test',
                'email' => 'concurrency@test.com',
                'password' => 'Password123!',
            ],
            files: [
                'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
            ],
        );

        return $result['attempt'];
    }

    public function test_concurrent_second_approval_fails_safely_without_duplicate_state(): void
    {
        $attempt = $this->createPendingAttempt();
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);

        $reviewer1 = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $reviewer2 = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        // First approval succeeds
        $service->approveRegistration($attempt, $reviewer1, ['import']);
        $attempt->refresh();
        $this->assertSame(SupplierRegistrationAttempt::STATUS_APPROVED, $attempt->status);

        // Second approval attempt on already approved record must fail safely
        $this->expectException(\DomainException::class);
        $service->approveRegistration($attempt, $reviewer2, ['local']);

        // Assert user still has only 1 scope ('import') and no duplicate scopes
        $user = $attempt->user->fresh();
        $this->assertSame(1, $user->supplierScopes()->count());
        $this->assertTrue($user->hasSupplierScope('import'));
    }

    public function test_concurrent_duplicate_registration_fails(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);

        $payload = [
            'company_title' => 'PT',
            'company_name' => 'PT Duplicate Race',
            'address' => 'Jl. Race No. 2',
            'phone' => '021-445566',
            'category' => 'Supplier',
            'nib' => '4444444444444',
            'npwp' => '44.444.444.4-444.000',
            'pic_name' => 'Race',
            'pic_email' => 'race@test.com',
            'pic_phone' => '084444444444',
            'bank_name' => 'BCA',
            'account_number' => '44444444',
            'account_holder_name' => 'PT Duplicate Race',
            'email' => 'race1@test.com',
            'password' => 'Password123!',
        ];

        $files = [
            'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
            'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
            'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
        ];

        // First registration succeeds
        $service->submitInitialRegistration($payload, $files);

        // Second registration with same NIB and NPWP must fail
        $payload2 = $payload;
        $payload2['email'] = 'race2@test.com'; // different email, but same NIB/NPWP

        $this->expectException(ValidationException::class);
        $service->submitInitialRegistration($payload2, $files);
    }
}
