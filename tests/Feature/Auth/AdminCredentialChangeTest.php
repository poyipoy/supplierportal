<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminCredentialChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfirmed_admin_cannot_create_or_reset_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'purchasing']);
        $before = $target->fresh()->getAttributes();
        $payload = ['name' => 'Target', 'email' => 'new-user@example.test', 'role' => 'purchasing', 'is_active' => '1',
            'password' => 'Temporary!Password123', 'password_confirmation' => 'Temporary!Password123'];
        $this->actingAs($admin)->postJson(route('admin.users.store'), $payload)->assertStatus(423);
        unset($payload['email']);
        $this->putJson(route('admin.users.update', $target), $payload)->assertStatus(423);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertDatabaseMissing('users', ['email' => 'new-user@example.test']);
        foreach (['create', 'edit', 'store', 'update'] as $action) {
            $this->assertContains('password.confirm', app('router')->getRoutes()->getByName('admin.users.'.$action)->gatherMiddleware());
        }
    }

    public function test_confirmed_admin_reset_revokes_session_and_requires_a_new_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'purchasing']);
        $oldVersion = $target->auth_session_version;
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp])->put(route('admin.users.update', $target), [
            'name' => $target->name, 'role' => 'purchasing', 'is_active' => '1',
            'password' => 'Temporary!Password123', 'password_confirmation' => 'Temporary!Password123',
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $target->refresh();
        $this->assertTrue($target->must_change_password);
        $this->assertGreaterThan($oldVersion, $target->auth_session_version);
        Notification::assertSentTo($target, SystemNotification::class);
        session()->forget('password_hash_web');
        $this->actingAs($target)->withSession(['auth_session_version' => $target->auth_session_version])
            ->get(route('purchasing.dashboard'))->assertRedirect(route('profile.security'));
        $this->getJson(route('exports.definitions.show', 'purchasing.purchase-orders'))->assertForbidden();
        $this->put(route('password.update'), ['current_password' => 'Temporary!Password123', 'password' => 'Permanent!Password456', 'password_confirmation' => 'Permanent!Password456'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Permanent!Password456', $target->fresh()->password));
    }
}
