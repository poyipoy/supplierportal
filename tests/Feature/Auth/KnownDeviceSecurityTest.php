<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Notifications\SystemNotification;
use App\Services\Auth\TwoFactorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class KnownDeviceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_successful_login_registers_a_hashed_device_and_queues_cookie(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->login($user, '198.51.100.10', 'Known Device Test Browser');
        $token = $response->getCookie($this->cookieName())?->getValue();

        $this->assertNotNull($token);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $token);
        $this->assertNotSame($token, $response->getCookie($this->cookieName(), false)->getValue());
        $response->assertCookie($this->cookieName());
        $this->assertDatabaseHas('auth_known_devices', [
            'user_id' => $user->id,
            'device_hash' => hash('sha256', $token),
            'last_ip_address' => '198.51.100.10',
            'last_user_agent' => 'Known Device Test Browser',
        ]);

        $device = DB::table('auth_known_devices')->where('user_id', $user->id)->sole();
        $this->assertNotNull($device->first_seen_at);
        $this->assertNotNull($device->last_seen_at);
        $this->assertNotSame($token, $device->device_hash);
        $this->assertDatabaseMissing('auth_known_devices', ['device_hash' => $token]);
    }

    public function test_first_device_login_sends_in_app_and_queued_email_notifications_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->login($user);

        Notification::assertSentTo($user, SystemNotification::class, function (SystemNotification $notification) use ($user): bool {
            $data = $notification->toDatabase($user);

            return $data['title'] === 'New sign-in detected'
                && $data['url'] === '/profile#active-sessions'
                && $data['icon'] === 'monitor';
        });
        Notification::assertSentTo($user, NewDeviceLoginNotification::class, function (NewDeviceLoginNotification $notification): bool {
            $this->assertInstanceOf(ShouldQueue::class, $notification);

            return true;
        });
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'new_device_login',
        ]);
    }

    public function test_second_login_with_same_device_updates_last_seen_without_repeating_alert(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $first = $this->login($user);
        $token = $first->getCookie($this->cookieName())->getValue();
        $firstSeen = Carbon::parse(DB::table('auth_known_devices')->value('last_seen_at'));

        $this->post('/logout');
        $this->travel(1)->minute();
        Notification::fake();

        $this->withCookie($this->cookieName(), $token);
        $second = $this->login($user);

        $this->assertDatabaseCount('auth_known_devices', 1);
        $this->assertTrue(Carbon::parse(DB::table('auth_known_devices')->value('last_seen_at'))->greaterThan($firstSeen));
        $this->assertSame($token, $second->getCookie($this->cookieName())->getValue());
        Notification::assertNotSentTo($user, SystemNotification::class);
        Notification::assertNotSentTo($user, NewDeviceLoginNotification::class);
    }

    public function test_ip_change_updates_metadata_but_does_not_make_the_device_new(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->login($user, '198.51.100.20')->getCookie($this->cookieName())->getValue();
        $this->post('/logout');
        Notification::fake();

        $this->withCookie($this->cookieName(), $token);
        $this->login($user, '203.0.113.20');

        $this->assertDatabaseHas('auth_known_devices', [
            'user_id' => $user->id,
            'device_hash' => hash('sha256', $token),
            'last_ip_address' => '203.0.113.20',
        ]);
        Notification::assertNothingSent();
    }

    public function test_user_agent_change_updates_metadata_but_does_not_make_the_device_new(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = $this->login($user, userAgent: 'Browser Version 1')->getCookie($this->cookieName())->getValue();
        $this->post('/logout');
        Notification::fake();

        $this->withCookie($this->cookieName(), $token);
        $this->login($user, userAgent: 'Browser Version 2');

        $this->assertDatabaseHas('auth_known_devices', [
            'user_id' => $user->id,
            'device_hash' => hash('sha256', $token),
            'last_user_agent' => 'Browser Version 2',
        ]);
        Notification::assertNothingSent();
    }

    public function test_same_user_agent_without_the_known_device_cookie_is_new(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->login($user, userAgent: 'Shared User Agent');
        $this->post('/logout');
        Notification::fake();

        $this->login($user, userAgent: 'Shared User Agent');

        $this->assertDatabaseCount('auth_known_devices', 2);
        Notification::assertSentTo($user, SystemNotification::class);
        Notification::assertSentTo($user, NewDeviceLoginNotification::class);
    }

    public function test_same_browser_token_is_registered_separately_for_each_account(): void
    {
        Notification::fake();
        $token = str_repeat('a', 64);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->withCookie($this->cookieName(), $token);
        $this->login($firstUser);
        $this->post('/logout');
        Notification::fake();

        $this->login($secondUser);

        $this->assertDatabaseHas('auth_known_devices', [
            'user_id' => $firstUser->id,
            'device_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseHas('auth_known_devices', [
            'user_id' => $secondUser->id,
            'device_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseCount('auth_known_devices', 2);
        Notification::assertSentTo($secondUser, SystemNotification::class);
        Notification::assertSentTo($secondUser, NewDeviceLoginNotification::class);
    }

    public function test_inactive_account_cannot_register_a_known_device(): void
    {
        Notification::fake();
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->login($user);

        $response->assertSessionHasErrors('email');
        $response->assertCookieMissing($this->cookieName());
        $this->assertDatabaseCount('auth_known_devices', 0);
        Notification::assertNothingSent();
    }

    public function test_mfa_pending_does_not_register_device_but_successful_challenge_does(): void
    {
        Notification::fake();
        [$user, $secret] = $this->mfaUser();

        $pending = $this->login($user);

        $pending->assertRedirect(route('two-factor.challenge'));
        $pending->assertCookieMissing($this->cookieName());
        $this->assertDatabaseCount('auth_known_devices', 0);
        Notification::assertNothingSent();

        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $completed = $this->post('/two-factor-challenge', ['code' => $code]);

        $completed->assertRedirect(route('dashboard', absolute: false));
        $completed->assertCookie($this->cookieName());
        $this->assertDatabaseHas('auth_known_devices', ['user_id' => $user->id]);
        Notification::assertSentTo($user, SystemNotification::class);
        Notification::assertSentTo($user, NewDeviceLoginNotification::class);
    }

    public function test_malformed_or_oversized_cookie_is_replaced_without_error(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $malformed = str_repeat('not-hex-', 100);

        $this->withCookie($this->cookieName(), $malformed);
        $response = $this->login($user);
        $replacement = $response->getCookie($this->cookieName())->getValue();

        $this->assertNotSame($malformed, $replacement);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $replacement);
        $this->assertDatabaseHas('auth_known_devices', [
            'user_id' => $user->id,
            'device_hash' => hash('sha256', $replacement),
        ]);
        Notification::assertSentTo($user, NewDeviceLoginNotification::class);
    }

    public function test_known_device_cookie_has_secure_production_attributes_and_400_day_lifetime(): void
    {
        Notification::fake();
        config()->set('session.secure', true);
        config()->set('session.domain', '.example.test');
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();

        $response = $this->login($user);
        $cookie = $response->getCookie($this->cookieName(), false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('.example.test', $cookie->getDomain());
        $this->assertSame(now()->addDays(400)->timestamp, $cookie->getExpiresTime());
    }

    private function login(
        User $user,
        string $ipAddress = '198.51.100.1',
        string $userAgent = 'Known Device Test Browser',
    ): TestResponse {
        return $this->withServerVariables([
            'REMOTE_ADDR' => $ipAddress,
            'HTTP_USER_AGENT' => $userAgent,
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
    }

    private function cookieName(): string
    {
        return (string) config('auth_security.known_device.cookie_name');
    }

    private function mfaUser(): array
    {
        $secret = app(TwoFactorService::class)->authenticator()->generateSecretKey(32);
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ])->saveQuietly();

        return [$user, $secret];
    }
}
