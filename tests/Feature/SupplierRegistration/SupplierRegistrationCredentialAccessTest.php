<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierRegistrationAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierRegistrationCredentialAccessTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'procurement@bajabersama.com';

    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function register(): User
    {
        $response = $this->post(route('supplier.register.store'), [
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
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'nib_file' => UploadedFile::fake()->create('nib.pdf', 500, 'application/pdf'),
            'npwp_file' => UploadedFile::fake()->create('npwp.jpg', 400, 'image/jpeg'),
            'sknr_file' => UploadedFile::fake()->create('sknr.pdf', 600, 'application/pdf'),
        ]);

        $response->assertRedirect(route('supplier.registration.success'));

        return User::where('email', self::EMAIL)->firstOrFail();
    }

    private function credentials(array $overrides = []): array
    {
        return array_merge(['email' => self::EMAIL, 'password' => self::PASSWORD], $overrides);
    }

    private function assertGenericFailure($response): void
    {
        $response->assertRedirect();
        $response->assertSessionHasErrors('email', null, 'credentials');
        $this->assertSame(__('registration.feedback.invalid_credentials'), session('errors')->getBag('credentials')->first('email'));
        $this->assertNull(session('registration_access_id'));
        $this->assertGuest();
    }

    public function test_success_page_exposes_receipt_once(): void
    {
        $this->register();

        $this->get(route('supplier.registration.success'))->assertOk();

        // Flash is consumed by the redirect chain above: a refresh must not re-expose the key.
        $this->get(route('supplier.registration.success'))
            ->assertRedirect(route('supplier.registration.access-form'));
    }

    public function test_receipt_contains_reference_key_company_and_print_controls(): void
    {
        $this->register();

        $response = $this->get(route('supplier.registration.success'));

        $response->assertOk()
            ->assertSee('id="receipt_print"', false)
            ->assertSee('id="receipt_copy_both"', false)
            ->assertSee('id="registration_receipt"', false)
            ->assertSee('PT Baja Bersama Abadi')
            ->assertSee(__('registration.receipt.title'))
            ->assertSee(__('registration.receipt.submitted_at'));

        $access = SupplierRegistrationAccess::firstOrFail();
        $response->assertSee($access->registration_reference);
    }

    public function test_pending_applicant_can_open_status_with_email_and_password(): void
    {
        $user = $this->register();

        $response = $this->post(route('supplier.registration.access.credentials'), $this->credentials());

        $response->assertRedirect(route('supplier.registration.status'));
        $this->assertGuest();
        $this->assertSame($user->id, session('registration_user_id'));

        $this->get(route('supplier.registration.status'))->assertOk();

        $this->assertDatabaseHas('supplier_registration_audits', [
            'user_id' => $user->id,
            'event' => 'status_accessed_credentials',
            'actor_role' => 'applicant',
        ]);
    }

    public function test_email_is_normalized_before_lookup(): void
    {
        $this->register();

        $this->post(route('supplier.registration.access.credentials'), $this->credentials([
            'email' => '  PROCUREMENT@BajaBersama.com ',
        ]))->assertRedirect(route('supplier.registration.status'));
    }

    public function test_revision_applicant_can_access_status(): void
    {
        $user = $this->register();
        $user->registrationAttempts()->update(['status' => SupplierRegistrationAttempt::STATUS_REVISION]);

        $this->post(route('supplier.registration.access.credentials'), $this->credentials())
            ->assertRedirect(route('supplier.registration.status'));
    }

    public function test_wrong_password_and_unknown_email_share_one_generic_error(): void
    {
        $this->register();

        $this->assertGenericFailure(
            $this->post(route('supplier.registration.access.credentials'), $this->credentials(['password' => 'WrongPassword1!']))
        );
        $this->assertGenericFailure(
            $this->post(route('supplier.registration.access.credentials'), $this->credentials(['email' => 'nobody@example.com']))
        );
    }

    public function test_failure_preserves_credentials_tab_without_echoing_password(): void
    {
        $this->register();

        $this->from(route('supplier.registration.access-form'))
            ->post(route('supplier.registration.access.credentials'), $this->credentials(['password' => 'WrongPassword1!']))
            ->assertRedirect(route('supplier.registration.access-form'))
            ->assertSessionHas('access_tab', 'credentials')
            ->assertSessionHasInput('email', self::EMAIL)
            ->assertSessionMissing('_old_input.password');
    }

    public function test_decided_registrations_cannot_use_credential_access(): void
    {
        $user = $this->register();

        foreach ([SupplierRegistrationAttempt::STATUS_REJECTED, SupplierRegistrationAttempt::STATUS_APPROVED] as $status) {
            $user->registrationAttempts()->update(['status' => $status]);

            $this->assertGenericFailure(
                $this->post(route('supplier.registration.access.credentials'), $this->credentials())
            );
        }
    }

    public function test_revoked_access_row_fails_closed(): void
    {
        $user = $this->register();
        SupplierRegistrationAccess::where('user_id', $user->id)->update(['revoked_at' => now()]);

        $this->assertGenericFailure(
            $this->post(route('supplier.registration.access.credentials'), $this->credentials())
        );
    }

    public function test_non_supplier_role_cannot_use_credential_access(): void
    {
        $this->register();
        User::where('email', self::EMAIL)->update(['role' => 'purchasing']);

        $this->assertGenericFailure(
            $this->post(route('supplier.registration.access.credentials'), $this->credentials())
        );
    }

    public function test_expired_access_is_renewed_after_password_is_proven(): void
    {
        $user = $this->register();
        SupplierRegistrationAccess::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

        $this->post(route('supplier.registration.access.credentials'), $this->credentials())
            ->assertRedirect(route('supplier.registration.status'));

        $ttl = (int) config('supplier_registration.access.token_ttl_days');
        $this->assertSame(30, $ttl);
        $this->assertTrue(SupplierRegistrationAccess::where('user_id', $user->id)->firstOrFail()->expires_at->isAfter(now()->addDays($ttl - 1)));
    }

    public function test_credential_session_does_not_grant_operational_access(): void
    {
        $this->register();

        $this->post(route('supplier.registration.access.credentials'), $this->credentials());

        $this->assertGuest();
        $this->get(route('supplier.dashboard'))->assertRedirect(route('login'));
    }

    public function test_credential_session_cannot_download_a_document_owned_by_someone_else(): void
    {
        $this->register();
        $other = User::factory()->create(['role' => 'supplier']);

        $document = SupplierMasterDocument::query()->firstOrFail();
        $document->forceFill(['supplier_id' => $other->id])->save();

        $this->post(route('supplier.registration.access.credentials'), $this->credentials());

        $this->get(route('supplier.registration.document.download', $document))->assertForbidden();
    }

    public function test_repeated_failures_trigger_rate_limit_page_with_access_form_link(): void
    {
        $this->register();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('supplier.registration.access.credentials'), $this->credentials(['password' => 'WrongPassword1!']));
        }

        $this->post(route('supplier.registration.access.credentials'), $this->credentials())
            ->assertStatus(429)
            ->assertSee(route('supplier.registration.access-form'), false);
    }

    public function test_access_key_flow_is_unchanged(): void
    {
        $this->register();
        $access = SupplierRegistrationAccess::firstOrFail();

        $this->post(route('supplier.registration.access'), [
            'reference' => $access->registration_reference,
            'access_key' => 'definitely-not-the-key',
        ])->assertSessionHas('error', __('registration.feedback.invalid_access'));
    }

    public function test_access_form_renders_both_tabs(): void
    {
        $this->get(route('supplier.registration.access-form'))
            ->assertOk()
            ->assertSee('id="access_tab_key"', false)
            ->assertSee('id="access_tab_credentials"', false)
            ->assertSee(route('supplier.registration.access.credentials'), false);
    }

    public function test_credential_access_audit_is_visible_to_reviewers(): void
    {
        $user = $this->register();
        $this->post(route('supplier.registration.access.credentials'), $this->credentials());

        $this->assertSame(1, SupplierRegistrationAudit::where('user_id', $user->id)->where('event', 'status_accessed_credentials')->count());
    }
}
