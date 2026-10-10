<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginEmailImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function updatePaths(): array
    {
        return [
            'profile without email' => [false, false],
            'profile with identical email' => [false, true],
            'admin without email' => [true, false],
            'admin with identical email' => [true, true],
        ];
    }

    #[DataProvider('updatePaths')]
    public function test_updates_preserve_login_email_and_security_metadata(bool $adminPath, bool $includeEmail): void
    {
        $target = User::factory()->create(['role' => 'purchasing'])->refresh();
        $original = $target->getAttributes();
        $actor = $adminPath ? User::factory()->create(['role' => 'admin']) : $target;
        $payload = ['name' => 'Updated Display Name'];
        if ($includeEmail) {
            $payload['email'] = $target->email;
        }
        if ($adminPath) {
            $payload += ['role' => $target->role, 'is_active' => '1'];
        } else {
            // Extra client fields must not turn a profile edit into a security action.
            $payload += ['role' => 'admin', 'auth_session_version' => 99, 'email_verified_at' => null];
        }

        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->auth_session_version]);
        $response = $adminPath
            ? $this->put(route('admin.users.update', $target), $payload)
            : $this->patch(route('profile.update'), $payload);
        $response->assertSessionHasNoErrors()->assertRedirect($adminPath ? route('admin.users.index') : route('profile.edit'));

        $target->refresh();
        $this->assertSame('Updated Display Name', $target->name);
        foreach (['email', 'email_verified_at', 'password', 'remember_token', 'auth_session_version', 'role', 'account_status', 'is_active'] as $field) {
            $this->assertSame($original[$field], $target->getRawOriginal($field), $field);
        }
        $this->assertAuthenticatedAs($actor);
        $this->assertSame($actor->auth_session_version, session('auth_session_version'));
    }

    public static function tamperedRequests(): array
    {
        return [
            'profile HTML' => [false, false], 'profile JSON' => [false, true],
            'admin HTML' => [true, false], 'admin JSON' => [true, true],
        ];
    }

    #[DataProvider('tamperedRequests')]
    public function test_email_tampering_rejects_the_entire_request(bool $adminPath, bool $json): void
    {
        $target = User::factory()->create(['role' => 'purchasing'])->refresh();
        $original = $target->getAttributes();
        $actor = $adminPath ? User::factory()->create(['role' => 'admin']) : $target;
        $payload = ['name' => 'Must Not Save', 'email' => 'replacement@example.com', 'role' => 'qc', 'is_active' => '1'];
        $url = $adminPath ? route('admin.users.update', $target) : route('profile.update');
        $this->actingAs($actor)->from($adminPath ? route('admin.users.edit', $target) : route('profile.edit'));
        $response = $json
            ? $this->json($adminPath ? 'PUT' : 'PATCH', $url, $payload)
            : $this->call($adminPath ? 'PUT' : 'PATCH', $url, $payload);
        if ($json) {
            $response->assertUnprocessable()->assertJsonValidationErrors('email')
                ->assertJsonPath('errors.email.0', __('profile.email_immutable'));
        } else {
            $response->assertSessionHasErrors(['email' => __('profile.email_immutable')]);
        }
        $this->assertSame($original, $target->fresh()->getAttributes());

        if (! $json) {
            $page = $this->get($adminPath ? route('admin.users.edit', $target) : route('profile.edit'))->assertOk();
            $page->assertSee($target->email)->assertDontSee('replacement@example.com');
        }
    }

    public static function modelWrites(): array
    {
        return ['save' => ['save'], 'update' => ['update'], 'forceFill' => ['forceFill']];
    }

    #[DataProvider('modelWrites')]
    public function test_existing_user_model_rejects_email_changes(string $method): void
    {
        $user = User::factory()->create()->refresh();
        $original = $user->getAttributes();
        $attributes = ['email' => 'replacement@example.com', 'name' => 'Must Not Save'];
        try {
            if ($method === 'update') {
                $user->update($attributes);
            } elseif ($method === 'forceFill') {
                $user->forceFill($attributes)->save();
            } else {
                $user->email = $attributes['email'];
                $user->name = $attributes['name'];
                $user->save();
            }
            $this->fail('Changing the login email must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('profile.email_immutable')], $exception->errors()['email']);
        }
        $this->assertSame($original, $user->fresh()->getAttributes());
    }

    public function test_admin_can_still_set_email_when_creating_an_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New User', 'email' => 'new.user@example.com', 'role' => 'purchasing',
            'is_active' => '1', 'password' => 'Strong!AccountPassword123',
            'password_confirmation' => 'Strong!AccountPassword123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'new.user@example.com', 'name' => 'New User']);
    }

    public function test_display_name_does_not_update_supplier_company_or_pic(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);
        $supplier = Supplier::create([
            'user_id' => $user->id, 'company_name' => 'Company Master',
            'pic_name' => 'Contact Person', 'pic_email' => 'pic@example.com',
        ]);
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Display Only'])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertSame('Company Master', $supplier->fresh()->company_name);
        $this->assertSame('Contact Person', $supplier->fresh()->pic_name);
        $supplier->update(['pic_email' => 'new.pic@example.com']);
        $this->assertSame('new.pic@example.com', $supplier->fresh()->pic_email);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public static function profileSummaries(): array
    {
        return [
            'English internal' => ['en', 'purchasing', [], false],
            'Indonesian internal' => ['id', 'finance', [], true],
            'import' => ['en', 'supplier', ['import'], false],
            'local' => ['id', 'supplier', ['local'], true],
            'dual' => ['en', 'supplier', ['import', 'local'], true],
            'no scope' => ['id', 'supplier', [], false],
        ];
    }

    #[DataProvider('profileSummaries')]
    public function test_profile_summary_is_localized_and_contains_only_account_controls(string $locale, string $role, array $scopes, bool $mfa): void
    {
        $user = User::factory()->create(['role' => $role, 'created_at' => '2026-10-08 23:30:00']);
        $user->supplierScopes()->delete();
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale, 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso']);
        foreach ($scopes as $scope) {
            SupplierScope::create(['supplier_id' => $user->id, 'scope' => $scope]);
        }
        if ($mfa) {
            $user->forceFill(['two_factor_secret' => 'test-secret', 'two_factor_confirmed_at' => now()])->save();
        }
        $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertViewHas('supplierScopes', $scopes);
        $response->assertSeeText(__('profile.login_email'))->assertSee($user->email)
            ->assertSeeText(__('profile.display_name'))
            ->assertSeeText(__('navigation.roles.'.$role))
            ->assertSeeText(__('profile.email_help'))
            ->assertSeeText(app(RegionalDisplayFormatter::class)->timestamp($user->created_at))
            ->assertSeeText($mfa ? __('security.two_factor_enabled') : __('security.not_enabled'))
            ->assertSee(route('profile.security').'#security-two-factor-title', false);
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertCount(0, $xpath->query('//input[@name="email"]'));
        $this->assertCount(1, $xpath->query('//form[@action="'.route('profile.update').'"]//input[@name="name"]'));
        $this->assertCount(0, $xpath->query('//form[@action="'.route('password.update').'"]'));
        if ($role === 'supplier') {
            foreach ($scopes as $scope) {
                $response->assertSeeText(__('profile.scope_'.$scope));
            }
            if ($scopes === []) {
                $response->assertSeeText(__('profile.no_portal_access'));
            }
        } else {
            $response->assertDontSeeText(__('profile.portal_access'));
        }
    }

    public function test_invalid_name_and_unauthorized_requests_cannot_update_users(): void
    {
        $this->patch(route('profile.update'), ['name' => 'Guest'])->assertRedirect(route('login'));
        $user = User::factory()->create(['role' => 'qc']);
        $other = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($user)->put(route('admin.users.update', $other), [
            'name' => 'Unauthorized', 'role' => 'admin',
        ])->assertForbidden();
        foreach (['', str_repeat('x', 256)] as $name) {
            $this->patchJson(route('profile.update'), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($other->name, $other->fresh()->name);
    }
}
