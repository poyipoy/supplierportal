<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierRegistrationReviewTest extends TestCase
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
                'company_name' => 'PT Logam Jaya Perkasa',
                'address' => 'Kawasan Industri KIIC, Karawang',
                'phone' => '0267-123456',
                'category' => 'Steel',
                'nib' => '8888888888888',
                'npwp' => '88.888.888.8-888.000',
                'pic_name' => 'Hendro',
                'pic_email' => 'hendro@logamjaya.com',
                'pic_phone' => '081288888888',
                'bank_name' => 'BCA',
                'account_number' => '888000111222',
                'account_holder_name' => 'PT Logam Jaya Perkasa',
                'email' => 'contact@logamjaya.com',
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

    public function test_reviewer_authorization_matrix(): void
    {
        $attempt = $this->createPendingAttempt();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        // Admin can view
        $this->actingAs($admin)->get(route('supplier-registrations.index'))->assertOk();
        $this->actingAs($admin)->get(route('supplier-registrations.show', $attempt->hash))->assertOk();

        // Finance can view
        $this->actingAs($finance)->get(route('supplier-registrations.index'))->assertOk();
        $this->actingAs($finance)->get(route('supplier-registrations.show', $attempt->hash))->assertOk();

        // Purchasing can view
        $this->actingAs($purchasing)->get(route('supplier-registrations.index'))->assertOk();
        $this->actingAs($purchasing)->get(route('supplier-registrations.show', $attempt->hash))->assertOk();

        // Supplier is blocked (403)
        $this->actingAs($supplier)->get(route('supplier-registrations.index'))->assertForbidden();

        // QC is blocked (403)
        $this->actingAs($qc)->get(route('supplier-registrations.index'))->assertForbidden();

        // Unauthenticated is redirected
        auth()->logout();
        $this->get(route('supplier-registrations.index'))->assertRedirect(route('login'));
    }

    public function test_single_reviewer_approval_with_scope_assignment(): void
    {
        $attempt = $this->createPendingAttempt();
        $purchasingReviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        // Purchasing approves with both scopes
        $response = $this->actingAs($purchasingReviewer)->post(route('supplier-registrations.approve', $attempt->hash), [
            'scopes' => ['import', 'local'],
            'notes' => 'Supplier verified and approved for both import and local procurement.',
        ]);

        $response->assertRedirect(route('supplier-registrations.show', $attempt->hash));

        $attempt->refresh();
        $user = $attempt->user->fresh();

        $this->assertSame(SupplierRegistrationAttempt::STATUS_APPROVED, $attempt->status);
        $this->assertSame($purchasingReviewer->id, $attempt->reviewed_by);
        $this->assertSame(User::ACCOUNT_STATUS_ACTIVE, $user->account_status);
        $this->assertTrue((bool) $user->is_active);

        // Verify scopes assigned
        $this->assertTrue($user->hasSupplierScope('import'));
        $this->assertTrue($user->hasSupplierScope('local'));

        // Verify access token revoked
        $this->assertNotNull($user->registrationAccesses()->latest()->first()->revoked_at);
    }

    public function test_approval_requires_at_least_one_valid_scope(): void
    {
        $attempt = $this->createPendingAttempt();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->post(route('supplier-registrations.approve', $attempt->hash), [
            'scopes' => [],
        ]);

        $response->assertSessionHasErrors('scopes');
    }

    public function test_reviewer_can_request_revision_with_reason(): void
    {
        $attempt = $this->createPendingAttempt();
        $financeReviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        // Attempt without reason fails validation
        $failResponse = $this->actingAs($financeReviewer)->post(route('supplier-registrations.revision', $attempt->hash), [
            'reason' => '',
        ]);
        $failResponse->assertSessionHasErrors('reason');

        // Valid reason
        $response = $this->actingAs($financeReviewer)->post(route('supplier-registrations.revision', $attempt->hash), [
            'reason' => 'Bank statement / SKNR does not match company legal name.',
        ]);

        $response->assertRedirect(route('supplier-registrations.show', $attempt->hash));

        $attempt->refresh();
        $this->assertSame(SupplierRegistrationAttempt::STATUS_REVISION, $attempt->status);
        $this->assertSame(User::ACCOUNT_STATUS_REVISION, $attempt->user->fresh()->account_status);
        $this->assertSame('Bank statement / SKNR does not match company legal name.', $attempt->reviewer_notes);
    }

    public function test_reviewer_can_reject_with_reason(): void
    {
        $attempt = $this->createPendingAttempt();
        $adminReviewer = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($adminReviewer)->post(route('supplier-registrations.reject', $attempt->hash), [
            'reason' => 'Supplier does not meet financial compliance standards.',
        ]);

        $response->assertRedirect(route('supplier-registrations.show', $attempt->hash));

        $attempt->refresh();
        $user = $attempt->user->fresh();

        $this->assertSame(SupplierRegistrationAttempt::STATUS_REJECTED, $attempt->status);
        $this->assertSame(User::ACCOUNT_STATUS_REJECTED, $user->account_status);
        $this->assertFalse((bool) $user->is_active);
        $this->assertNull($user->supplier->nib_fingerprint);
    }

    public function test_reviewer_can_download_document_and_cross_attempt_is_prevented(): void
    {
        $attempt1 = $this->createPendingAttempt();

        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $res2 = $service->submitInitialRegistration(
            data: [
                'company_title' => 'PT',
                'company_name' => 'PT Lain',
                'address' => 'Jl. Lain',
                'phone' => '021-999',
                'nib' => '7777777777777',
                'npwp' => '77.777.777.7-777.000',
                'pic_name' => 'Lain',
                'pic_email' => 'lain@lain.com',
                'pic_phone' => '089999',
                'bank_name' => 'BNI',
                'account_number' => '7777',
                'account_holder_name' => 'PT Lain',
                'email' => 'lain@lain.com',
                'password' => 'Password123!',
            ],
            files: [
                'nib_file' => UploadedFile::fake()->create('nib2.pdf', 500, 'application/pdf'),
                'npwp_file' => UploadedFile::fake()->create('npwp2.jpg', 400, 'image/jpeg'),
                'sknr_file' => UploadedFile::fake()->create('sknr2.pdf', 600, 'application/pdf'),
            ],
        );
        $attempt2 = $res2['attempt'];

        $doc1 = SupplierMasterDocument::where('supplier_id', $attempt1->user_id)->first();
        $doc2 = SupplierMasterDocument::where('supplier_id', $attempt2->user_id)->first();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        // Reviewer can download attempt1's document
        $this->actingAs($admin)->get(route('supplier-registrations.document', [
            'attempt' => $attempt1->hash,
            'document' => $doc1->hash,
        ]))->assertOk();

        // Attempting to download attempt2's document under attempt1's route URL is forbidden
        $this->actingAs($admin)->get(route('supplier-registrations.document', [
            'attempt' => $attempt1->hash,
            'document' => $doc2->hash,
        ]))->assertForbidden();
    }
}
