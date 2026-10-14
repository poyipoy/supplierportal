<?php

namespace Tests\Feature\Auth;

use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Auth\PasswordConfirmationContinuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200)
            ->assertSee('ADASI Supplier Portal')
            ->assertSee('Confirm your password')
            ->assertSee('Confirm and Continue');
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHasNoErrors();
    }

    public function test_password_confirmation_continues_mfa_setup_instead_of_returning_to_profile(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $start->assertSessionHas(PasswordConfirmationContinuationService::SESSION_KEY);

        $confirmation = $this->withCookie(config('session.cookie'), $start->getCookie(config('session.cookie'))->getValue())
            ->post(route('password.confirm'), [
                'password' => 'password',
            ]);

        $confirmation->assertOk()->assertViewIs('auth.password-confirmation-continuation');
        $confirmation->assertSessionMissing(PasswordConfirmationContinuationService::SESSION_KEY);

        $action = $confirmation->viewData('action');
        $this->assertSame('POST', $action['method']);
        $this->assertSame('/profile/two-factor/setup', parse_url($action['url'], PHP_URL_PATH));

        $this->post(parse_url($action['url'], PHP_URL_PATH), [
            ...collect($action['inputs'])->mapWithKeys(fn (array $input): array => [$input['name'] => $input['value']])->all(),
        ])->assertRedirect(route('profile.two-factor.setup'));

        $this->get(route('profile.two-factor.setup'))
            ->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_direct_password_confirmation_falls_back_to_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_json_password_confirmation_requirement_returns_locked_response_without_storing_action(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('profile.two-factor.start'));

        $response->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.'])
            ->assertSessionMissing(PasswordConfirmationContinuationService::SESSION_KEY);
    }

    public function test_expired_pending_action_is_not_replayed(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $payload = json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['expires_at'] = now()->subSecond()->timestamp;
        $this->startSession();
        $payload['session_id'] = $this->app['session']->getId();
        $encryptedPayload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->withSession([PasswordConfirmationContinuationService::SESSION_KEY => $encryptedPayload])
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_pending_action_bound_to_another_user_is_not_replayed(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $payload = json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['user_id'] = 'different-user';
        $this->startSession();
        $payload['session_id'] = $this->app['session']->getId();
        $encryptedPayload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->withSession([PasswordConfirmationContinuationService::SESSION_KEY => $encryptedPayload])
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_pending_action_bound_to_another_session_is_not_replayed(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('password.confirm'));
        $payload = json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload['session_id'] = 'different-session';

        $this->withSession([
            PasswordConfirmationContinuationService::SESSION_KEY => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ])
            ->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }

    /**
     * Buka halaman GET yang dilindungi, konfirmasi password, dan kembalikan
     * respons konfirmasinya (session diteruskan lewat cookie).
     */
    private function confirmAfterVisiting(User $user, string $url): TestResponse
    {
        $start = $this->actingAs($user)->get($url)->assertRedirect(route('password.confirm'));
        $start->assertSessionHas(PasswordConfirmationContinuationService::SESSION_KEY);

        return $this->withCookie(config('session.cookie'), $start->getCookie(config('session.cookie'))->getValue())
            ->post(route('password.confirm'), ['password' => 'password']);
    }

    public function test_confirmation_returns_to_admin_user_edit_page_instead_of_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'purchasing']);
        $url = route('admin.users.edit', $target);

        $this->confirmAfterVisiting($admin, $url)
            ->assertRedirect($url)
            ->assertSessionMissing(PasswordConfirmationContinuationService::SESSION_KEY);

        $this->get($url)->assertOk();
    }

    public function test_confirmation_returns_to_admin_user_create_page_and_keeps_query_string(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $url = route('admin.users.create', ['role' => 'supplier']);

        $this->confirmAfterVisiting($admin, $url)->assertRedirect($url);
    }

    public function test_confirmation_returns_to_finance_payment_term_form(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $vendor = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $vendor->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $vendor->id, 'company_name' => 'PT Uji', 'payment_term_days' => 30]);
        $url = route('finance.vendor-master.payment-term.edit', $vendor);

        $this->confirmAfterVisiting($finance, $url)->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('name="payment_term_days"', false);
    }

    public function test_pending_navigation_is_single_use(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $url = route('admin.users.create');

        $this->confirmAfterVisiting($admin, $url)->assertRedirect($url);

        // Konfirmasi berikutnya tanpa navigasi tertunda kembali ke perilaku default.
        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));
    }

    public function test_pending_navigation_bound_to_another_user_session_or_expired_is_ignored(): void
    {
        $payload = $this->capturedNavigationPayload();
        $this->assertSame('GET', $payload['method']);
        $this->assertSame([], $payload['inputs']);

        foreach (['user_id' => 'different-user', 'session_id' => 'different-session', 'expires_at' => now()->subSecond()->timestamp] as $field => $value) {
            $this->postConfirmationWithPayload(array_merge($payload, [$field => $value]), keepSession: $field !== 'session_id')
                ->assertRedirect(route('profile.edit'));
        }
    }

    public function test_pending_navigation_to_external_or_internal_endpoint_falls_back_to_profile(): void
    {
        $payload = $this->capturedNavigationPayload();

        foreach (['//evil.example/phish', 'https://evil.example/phish', '/exports/abc/status'] as $uri) {
            $response = $this->postConfirmationWithPayload(array_merge($payload, ['uri' => $uri]));

            $response->assertRedirect(route('profile.edit'));
            $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedNavigationPayload(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $start = $this->actingAs($admin)->get(route('admin.users.create'))->assertRedirect(route('password.confirm'));

        return json_decode(
            Crypt::decryptString($start->getSession()->get(PasswordConfirmationContinuationService::SESSION_KEY)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postConfirmationWithPayload(array $payload, bool $keepSession = true): TestResponse
    {
        $this->startSession();
        if ($keepSession) {
            $payload['session_id'] = $this->app['session']->getId();
        }

        return $this->withSession([PasswordConfirmationContinuationService::SESSION_KEY => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))])
            ->post(route('password.confirm'), ['password' => 'password']);
    }
}
