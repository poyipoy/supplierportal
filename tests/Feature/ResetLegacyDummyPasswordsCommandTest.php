<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetLegacyDummyPasswordsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_updates_legacy_non_argon2id_passwords_by_default(): void
    {
        $legacyUser = User::factory()->create();
        DB::table('users')
            ->where('id', $legacyUser->id)
            ->update(['password' => '$2y$12$e80yq9M2XvH0WJ1aR8P1eeS4xLgXk2.mK6uV4C7W1A4rK5eS8V1ee']);

        $customArgonHash = Hash::driver('argon2id')->make('SecretCustomPass!99');
        $modernUser = User::factory()->create();
        DB::table('users')
            ->where('id', $modernUser->id)
            ->update(['password' => $customArgonHash]);

        $this->artisan('auth:reset-legacy-dummy-passwords')
            ->assertExitCode(0);

        $legacyUser->refresh();
        $modernUser->refresh();

        $this->assertStringStartsWith('$argon2id$', $legacyUser->password);
        $this->assertTrue(Hash::driver('argon2id')->check('password', $legacyUser->password));
        $this->assertSame($customArgonHash, $modernUser->password);
        $this->assertTrue(Hash::driver('argon2id')->check('SecretCustomPass!99', $modernUser->password));
    }

    public function test_it_resets_all_users_when_all_option_is_passed(): void
    {
        $modernUser = User::factory()->create();
        DB::table('users')
            ->where('id', $modernUser->id)
            ->update(['password' => Hash::driver('argon2id')->make('AnotherPass!88')]);

        $this->artisan('auth:reset-legacy-dummy-passwords', ['--all' => true])
            ->assertExitCode(0);

        $modernUser->refresh();
        $this->assertTrue(Hash::driver('argon2id')->check('password', $modernUser->password));
    }
}
