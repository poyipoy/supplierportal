<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ResetLocalUsersPasswordSeeder extends Seeder
{
    /**
     * Set all existing user passwords to "password" using the active Argon2id hasher
     * for local/testing development environments only.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ResetLocalUsersPasswordSeeder hanya boleh dijalankan pada environment local atau testing.');
        }

        $updated = 0;

        DB::transaction(function () use (&$updated): void {
            User::query()->select(['id', 'password'])->chunkById(100, function ($users) use (&$updated): void {
                foreach ($users as $user) {
                    $user->forceFill([
                        'password' => Hash::driver('argon2id')->make('password'),
                    ])->saveQuietly();
                    $updated++;
                }
            });
        });

        $this->command?->info("Berhasil memperbarui password Argon2id untuk {$updated} user ke nilai default pengembangan.");
    }
}
