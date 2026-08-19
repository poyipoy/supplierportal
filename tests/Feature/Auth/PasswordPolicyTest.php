<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\AdasiResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_password_update_rejects_weak_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
        ])->assertRedirect('/profile')->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_admin_creation_and_update_use_the_global_password_policy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Weak User',
            'email' => 'weak-user@example.test',
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
            'role' => 'purchasing',
            'is_active' => '1',
        ])->assertSessionHasErrors('password');

        $target = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($admin)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
            'role' => 'purchasing',
            'is_active' => '1',
        ])->assertSessionHasErrors('password');
    }

    public function test_password_reset_rejects_weak_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, AdasiResetPasswordNotification::class, function (AdasiResetPasswordNotification $notification) use ($user): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'weakpassword',
                'password_confirmation' => 'weakpassword',
            ])->assertSessionHasErrors('password');

            return true;
        });
    }

    public function test_existing_legacy_password_still_allows_login(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_oversized_password_input(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => str_repeat('x', 256),
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }
}
