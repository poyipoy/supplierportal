<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierBankAccount;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierRegistrationAudit;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeFileFixtures;
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
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
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
            'vendor_category' => 'Barang',
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

    public function test_approval_with_confirmation_assigns_vendor_category_and_verifies_the_bank_account(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $user = $attempt->user;

        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $user->supplierBankAccounts()->sole()->status);
        $this->assertNull($user->supplier->vendor_category);
        $this->assertFalse($user->supplier->requiresSuratJalan());

        $this->actingAs($reviewer)->post(route('supplier-registrations.approve', $attempt->hash), [
            'scopes' => ['local'],
            'vendor_category' => 'Barang',
            'confirm_bank_declaration' => '1',
        ])->assertRedirect(route('supplier-registrations.show', $attempt->hash))
            ->assertSessionMissing('warning');

        $account = $user->supplierBankAccounts()->sole()->fresh();
        $this->assertSame(SupplierBankAccount::STATUS_VERIFIED, $account->status);
        $this->assertSame($reviewer->id, $account->verified_by);
        $this->assertNotNull($account->activated_at);
        $this->assertTrue($user->supplier->fresh()->requiresSuratJalan());
        $this->assertSame($account->id, $user->supplier->fresh()->activeBankAccount?->id);

        $this->assertDatabaseHas('supplier_registration_audits', [
            'attempt_id' => $attempt->id,
            'event' => 'bank_account_verified',
            'actor_id' => $reviewer->id,
        ]);
    }

    public function test_approval_without_a_bank_declaration_document_keeps_the_account_pending(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $user = $attempt->user;

        SupplierMasterDocument::query()
            ->where('supplier_id', $user->id)
            ->where('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
            ->delete();

        app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Jasa', null, true);

        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $user->supplierBankAccounts()->sole()->fresh()->status);
        $this->assertSame('Jasa', $user->supplier->fresh()->vendor_category);
        $this->assertFalse($user->supplier->fresh()->requiresSuratJalan());
        $this->assertDatabaseMissing('supplier_registration_audits', [
            'attempt_id' => $attempt->id,
            'event' => 'bank_account_verified',
        ]);
    }

    public function test_approval_without_the_reviewer_confirmation_keeps_the_account_pending_and_warns(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $user = $attempt->user;

        $this->actingAs($reviewer)->post(route('supplier-registrations.approve', $attempt->hash), [
            'scopes' => ['local'],
            'vendor_category' => 'Barang',
        ])->assertRedirect(route('supplier-registrations.show', $attempt->hash))
            ->assertSessionHas('warning', __('local_procurement.registration.approved_bank_pending'));

        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $user->supplierBankAccounts()->sole()->fresh()->status);
        $this->assertSame(SupplierRegistrationAttempt::STATUS_APPROVED, $attempt->fresh()->status);
        $this->assertSame('Barang', $user->supplier->fresh()->vendor_category);
    }

    public function test_approval_does_not_verify_an_account_that_differs_from_the_submitted_snapshot(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $user = $attempt->user;

        // The row changed after the reviewer-visible submission; the SKNR no longer vouches for it.
        $user->supplierBankAccounts()->sole()->update(['account_number' => '999000111222']);

        app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Barang', null, true);

        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $user->supplierBankAccounts()->sole()->fresh()->status);
        $this->assertSame(SupplierRegistrationAttempt::STATUS_APPROVED, $attempt->fresh()->status);
    }

    public function test_approval_does_not_verify_when_the_declaration_is_linked_to_another_account(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $user = $attempt->user;

        $other = SupplierBankAccount::create([
            'supplier_id' => $user->id,
            'bank_name' => 'MANDIRI',
            'account_number' => '123123123',
            'account_holder_name' => 'PT Lain',
            'status' => SupplierBankAccount::STATUS_INACTIVE,
        ]);
        SupplierMasterDocument::query()
            ->where('supplier_id', $user->id)
            ->where('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
            ->update(['supplier_bank_account_id' => $other->id]);

        app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Barang', null, true);

        $this->assertSame(
            SupplierBankAccount::STATUS_PENDING,
            $user->supplierBankAccounts()->where('account_number', '888000111222')->sole()->status,
        );
    }

    public function test_approval_rejects_an_unknown_vendor_category_at_the_service_boundary(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        try {
            app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Supplier');
            $this->fail('An unknown vendor category must not be accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vendor_category', $e->errors());
        }

        $this->assertSame(SupplierRegistrationAttempt::STATUS_PENDING, $attempt->fresh()->status);
    }

    public function test_approve_modal_shows_the_submitted_bank_account_and_the_confirmation_checkbox(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->actingAs($reviewer)->get(route('supplier-registrations.show', $attempt->hash))
            ->assertOk()
            ->assertSee('name="confirm_bank_declaration"', false)
            ->assertSee('888000111222');
    }

    public function test_approval_audit_records_the_previous_vendor_category_and_bank_outcome(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Jasa', null, false);

        $audit = SupplierRegistrationAudit::query()
            ->where('attempt_id', $attempt->id)->where('event', 'registration_approved')->sole();
        $this->assertArrayHasKey('vendor_category_before', $audit->metadata);
        $this->assertNull($audit->metadata['vendor_category_before']);
        $this->assertSame('Jasa', $audit->metadata['vendor_category']);
        $this->assertSame('not_confirmed', $audit->metadata['bank_account']['reason']);
    }

    public function test_approval_does_not_verify_when_the_declaration_file_is_unreadable(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user = $attempt->user;

        $document = SupplierMasterDocument::query()
            ->where('supplier_id', $user->id)
            ->where('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
            ->sole();
        Storage::disk('private')->delete($document->file_path);

        app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Barang', null, true);

        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $user->supplierBankAccounts()->sole()->fresh()->status);
    }

    public function test_bank_verification_on_approval_is_written_to_the_finance_audit_log(): void
    {
        $attempt = $this->createPendingAttempt();
        $reviewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        app(SupplierRegistrationService::class)->approveRegistration($attempt, $reviewer, ['local'], 'Barang', null, true);

        $account = $attempt->user->supplierBankAccounts()->sole();
        $this->assertDatabaseHas('local_finance_audit_logs', [
            'auditable_type' => SupplierBankAccount::class,
            'auditable_id' => $account->id,
            'action' => 'vendor_bank_verified_on_registration',
            'actor_id' => $reviewer->id,
        ]);
    }

    public function test_approval_requires_a_known_vendor_category_and_changes_nothing_without_it(): void
    {
        $attempt = $this->createPendingAttempt();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        foreach ([null, 'Supplier'] as $invalid) {
            $this->actingAs($admin)->post(route('supplier-registrations.approve', $attempt->hash), array_filter([
                'scopes' => ['import'],
                'vendor_category' => $invalid,
            ]))->assertSessionHasErrors('vendor_category');
        }

        $this->assertSame(SupplierRegistrationAttempt::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertSame(SupplierBankAccount::STATUS_PENDING, $attempt->user->supplierBankAccounts()->sole()->status);
    }

    public function test_approval_requires_at_least_one_valid_scope(): void
    {
        $attempt = $this->createPendingAttempt();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->post(route('supplier-registrations.approve', $attempt->hash), [
            'scopes' => [],
            'vendor_category' => 'Barang',
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
                'nib_file' => NativeFileFixtures::upload('nib2.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp2.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr2.pdf', 600),
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

    public function test_reviewer_view_keeps_business_details_without_technical_snapshot_controls(): void
    {
        $attempt = $this->createPendingAttempt();
        $submitted = $attempt->submission_snapshot;
        $submitted['revision_notes'] = 'Updated the bank declaration requested by the reviewer.';
        $attempt->update([
            'submission_snapshot' => $submitted,
            'submission_checksum' => hash('sha256', json_encode($submitted)),
        ]);
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $snapshot = $attempt->submission_snapshot;
        $checksum = $attempt->submission_checksum;

        $response = $this->actingAs($finance)->get(route('supplier-registrations.show', $attempt->hash));

        $response->assertOk();

        $response->assertDontSee(__('local_procurement.registration.snapshot', ['attempt' => $attempt->attempt_number]));
        $response->assertDontSee('SHA-256 Verified');
        $response->assertDontSee($checksum);

        $response->assertSee(__('local_procurement.registration.company_tax'));
        $response->assertSee('PT Logam Jaya Perkasa');
        $response->assertSee('8888888888888'); // NIB
        $response->assertSee('88.888.888.8-888.000'); // NPWP
        $response->assertSee('Hendro'); // PIC
        $response->assertSee('BCA'); // Bank
        $response->assertSee('888000111222'); // Account number

        $response->assertSee('nib.pdf');
        $response->assertSee($submitted['revision_notes']);
        $response->assertSee(__('local_procurement.registration.attempts'));
        $response->assertSee(__('local_procurement.registration.audit_trail'));

        $response->assertDontSee(__('local_procurement.registration.snapshot_view_raw'));
        $response->assertDontSee(__('local_procurement.registration.snapshot_copy_json'));
        $response->assertDontSee('x-ref="rawJsonContent"', false);
        $this->assertSame($snapshot, $attempt->fresh()->submission_snapshot);
        $this->assertSame($checksum, $attempt->fresh()->submission_checksum);
    }
}
