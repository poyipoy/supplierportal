<?php

namespace Tests\Feature\SupplierRegistration;

use App\Models\Supplier;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use App\Services\SupplierRegistrationService;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
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
            'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
            'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
            'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
            'questionnaire' => SupplierComplianceQuestionnaire::QUESTIONS,
        ], $overrides);
    }

    public static function duplicateFields(): array
    {
        return [['email'], ['nib'], ['npwp']];
    }

    #[DataProvider('duplicateFields')]
    public function test_duplicate_identity_is_rejected_on_its_field_without_changing_the_existing_application(string $field): void
    {
        $existing = $this->validPayload();
        $this->postJson(route('supplier.register.store'), $existing)->assertOk()
            ->assertExactJson(['redirect' => route('supplier.registration.success')]);
        $receipt = session('registration_receipt');
        $this->get(route('supplier.registration.success'))->assertOk();
        $user = User::where('email', $existing['email'])->sole();
        $before = $user->getAttributes();
        $accessBefore = $user->registrationAccesses()->sole()->getAttributes();
        $files = Storage::disk('private')->allFiles();
        $duplicate = ['email' => 'another@example.test', 'nib' => '9999999999999', 'npwp' => '99.999.999.9-999.000'];
        $duplicate[$field] = $existing[$field];

        $response = $this->postJson(route('supplier.register.store'), $this->validPayload($duplicate));
        $response->assertUnprocessable()->assertJsonValidationErrors($field)
            ->assertJsonPath('errors.'.$field.'.0', __('registration.duplicate.'.$field));
        $this->assertArrayNotHasKey('redirect', $response->json());
        $response->assertDontSee($receipt['access_key'], false);
        $this->get(route('supplier.registration.success'))->assertRedirect(route('supplier.registration.access-form'));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, User::count());
        $this->assertSame(1, $user->registrationAttempts()->count());
        $this->assertSame($files, Storage::disk('private')->allFiles());
        $this->assertSame($accessBefore, $user->registrationAccesses()->sole()->getAttributes());
        $this->assertNull(session('registration_receipt'));
    }

    public function test_duplicate_registration_still_validates_native_file_content(): void
    {
        $this->postJson(route('supplier.register.store'), $this->validPayload())->assertOk();

        $this->postJson(route('supplier.register.store'), $this->validPayload([
            'nib_file' => UploadedFile::fake()->createWithContent('nib.pdf', '%PDF-1.4'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('nib_file');

        $this->assertSame(1, User::count());
    }

    public function test_new_http_registration_appears_in_every_reviewer_list_and_search(): void
    {
        $response = $this->postJson(route('supplier.register.store'), $this->validPayload());
        $response->assertOk()->assertExactJson(['redirect' => route('supplier.registration.success')]);
        $receipt = session('registration_receipt');
        $attempt = SupplierRegistrationAttempt::sole();
        $this->assertSame(SupplierRegistrationAttempt::STATUS_PENDING, $attempt->status);
        $this->assertSame(1, SupplierRegistrationAccess::count());
        $this->assertSame(hash('sha256', $receipt['access_key']), SupplierRegistrationAccess::sole()->token_hash);
        $response->assertDontSee($receipt['access_key'], false);

        foreach (['admin', 'finance', 'purchasing'] as $role) {
            $reviewer = User::factory()->create(['role' => $role]);
            $this->actingAs($reviewer)->get(route('supplier-registrations.index'))
                ->assertOk()->assertSee($receipt['reference'])->assertSee('PT Baja Bersama Abadi');
            foreach (['PT Baja Bersama Abadi', $receipt['reference']] as $search) {
                $this->get(route('supplier-registrations.index', ['status' => 'PENDING', 'search' => $search]))
                    ->assertOk()->assertSee($receipt['reference']);
            }
            $fragment = $this->getJson(route('supplier-registrations.index', ['status' => 'PENDING']),
                ['X-Requested-With' => 'XMLHttpRequest', 'X-Adasi-Server-Tabs' => '1']);
            $fragment->assertOk()->assertJsonPath('tab', 'PENDING');
            $this->assertStringContainsString($receipt['reference'], $fragment->json('html'));
        }
        foreach (['supplier', 'qc', 'ga'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('supplier-registrations.index'))->assertForbidden();
        }
    }

    public function test_transaction_failure_does_not_leave_an_application_or_success_credentials(): void
    {
        $this->withoutExceptionHandling();
        $service = \Mockery::mock(SupplierRegistrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('notifyReviewers')->once()->andThrow(new \RuntimeException('Injected registration failure'));
        $this->app->instance(SupplierRegistrationService::class, $service);
        try {
            $this->postJson(route('supplier.register.store'), $this->validPayload());
            $this->fail('The transaction should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected registration failure', $exception->getMessage());
        }
        $this->assertSame(0, User::count());
        $this->assertSame(0, SupplierRegistrationAttempt::count());
        $this->assertSame(0, SupplierRegistrationAccess::count());
        $this->assertNull(session('registration_receipt'));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[DataProvider('duplicateFields')]
    public function test_unique_constraint_conflict_is_returned_on_the_matching_identity_field(string $field): void
    {
        $constraint = match ($field) {
            'email' => 'users_email_unique',
            'nib' => 'suppliers_nib_fingerprint_unique',
            'npwp' => 'suppliers_tax_identity_fingerprint_unique',
        };
        $cause = new \PDOException('Duplicate entry for key '.$constraint);
        $cause->errorInfo = ['23000', 1062];
        $service = \Mockery::mock(SupplierRegistrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('notifyReviewers')->once()->andThrow(new QueryException('mysql', 'insert', [], $cause));
        $this->app->instance(SupplierRegistrationService::class, $service);
        $this->postJson(route('supplier.register.store'), $this->validPayload())
            ->assertUnprocessable()->assertJsonValidationErrors($field)
            ->assertJsonPath('errors.'.$field.'.0', __('registration.duplicate.'.$field));
        $this->assertSame(0, User::count());
        $this->assertSame(0, SupplierRegistrationAttempt::count());
        $this->assertNull(session('registration_receipt'));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public static function incompleteTurnstileKeys(): array
    {
        return [['site', null], [null, 'secret']];
    }

    public static function disabledTurnstileKeys(): array
    {
        return [[null, null], ['', ''], [' ', ' ']];
    }

    #[DataProvider('disabledTurnstileKeys')]
    public function test_production_registration_works_without_captcha_when_both_keys_are_empty(?string $site, ?string $secret): void
    {
        $this->app->instance('env', 'production');
        config(['auth_security.turnstile.site_key' => $site, 'auth_security.turnstile.secret_key' => $secret]);
        Http::preventStrayRequests();
        $this->get(route('supplier.register'))->assertOk()->assertDontSee('class="cf-turnstile"', false);
        $this->withSession(['_token' => 'test-csrf'])->postJson(route('supplier.register.store'), $this->validPayload(['_token' => 'test-csrf']))
            ->assertOk()->assertExactJson(['redirect' => route('supplier.registration.success')]);
        $user = User::where('email', 'procurement@bajabersama.com')->sole();
        $this->assertSame(User::ACCOUNT_STATUS_PENDING, $user->account_status);
        $this->assertFalse($user->is_active);
        Http::assertNothingSent();
    }

    public function test_production_registration_accepts_compliant_password_without_breach_lookup(): void
    {
        $this->app->instance('env', 'production');
        config(['auth_security.password.uncompromised_in_production' => true,
            'auth_security.turnstile.site_key' => 'site', 'auth_security.turnstile.secret_key' => 'secret']);
        $this->mock(UncompromisedVerifier::class, function ($mock): void {
            $mock->shouldNotReceive('verify');
        });
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->withSession(['_token' => 'test-csrf'])->postJson(route('supplier.register.store'), $this->validPayload([
            '_token' => 'test-csrf', 'cf-turnstile-response' => 'valid-token',
        ]))->assertOk()->assertExactJson(['redirect' => route('supplier.registration.success')]);
        $this->assertTrue(Hash::check('Password123!', User::where('email', 'procurement@bajabersama.com')->sole()->password));
        Http::assertSentCount(1);
    }

    #[DataProvider('incompleteTurnstileKeys')]
    public function test_production_registration_blocks_incomplete_turnstile_configuration(?string $site, ?string $secret): void
    {
        $this->app->instance('env', 'production');
        config(['auth_security.password.uncompromised_in_production' => false,
            'auth_security.turnstile.site_key' => $site, 'auth_security.turnstile.secret_key' => $secret]);
        Http::preventStrayRequests();

        $this->withSession(['_token' => 'test-csrf'])->postJson(route('supplier.register.store'), $this->validPayload(['_token' => 'test-csrf']))
            ->assertUnprocessable()->assertJsonValidationErrors('cf-turnstile-response');
        $this->assertSame(0, User::count());
        Http::assertNothingSent();
    }

    public static function providerFailures(): array
    {
        return [['invalid'], ['server'], ['connection']];
    }

    #[DataProvider('providerFailures')]
    public function test_registration_blocks_turnstile_failure(string $failure): void
    {
        config(['auth_security.turnstile.site_key' => 'site', 'auth_security.turnstile.secret_key' => 'secret']);
        Http::fake(fn () => match ($failure) {
            'server' => Http::response([], 503),
            'connection' => throw new ConnectionException('Timeout'),
            default => Http::response(['success' => false]),
        });

        $this->postJson(route('supplier.register.store'), $this->validPayload(['cf-turnstile-response' => 'test-token']))
            ->assertUnprocessable()->assertJsonValidationErrors('cf-turnstile-response');
        $this->assertSame(0, User::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_registration_accepts_a_valid_turnstile_response(): void
    {
        config(['auth_security.turnstile.site_key' => 'site', 'auth_security.turnstile.secret_key' => 'secret']);
        Http::fake(fn () => Http::response(['success' => true]));

        $this->postJson(route('supplier.register.store'), $this->validPayload(['cf-turnstile-response' => 'test-token']))->assertOk();
        $this->assertSame(1, User::count());
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
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
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
        $this->assertSame(1, User::count());
    }

    public function test_registration_access_session_cannot_access_portal_routes(): void
    {
        /** @var SupplierRegistrationService $service */
        $service = app(SupplierRegistrationService::class);
        $result = $service->submitInitialRegistration(
            data: $this->validPayload(),
            files: [
                'nib_file' => NativeFileFixtures::upload('nib.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr.pdf', 600),
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
                'nib_file' => NativeFileFixtures::upload('nib1.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp1.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr1.pdf', 600),
            ],
        );

        // Supplier 2
        $res2 = $service->submitInitialRegistration(
            data: $this->validPayload(['email' => 'supplier2@test.com', 'nib' => '2222222222222', 'npwp' => '22.222.222.2-222.000']),
            files: [
                'nib_file' => NativeFileFixtures::upload('nib2.pdf', 500),
                'npwp_file' => UploadedFile::fake()->image('npwp2.jpg', 20, 20),
                'sknr_file' => NativeFileFixtures::upload('sknr2.pdf', 600),
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
