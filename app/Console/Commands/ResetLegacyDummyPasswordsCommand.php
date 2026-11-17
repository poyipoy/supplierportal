<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Reset legacy non-Argon2id dummy account passwords to "password" for dummy/staging cPanel runs.
 * Idempotent by default: skips accounts already hashed with Argon2id unless --all is specified.
 */
class ResetLegacyDummyPasswordsCommand extends Command
{
    protected $signature = 'auth:reset-legacy-dummy-passwords
                            {--all : Reset all users instead of only non-Argon2id hashes}
                            {--force : Allow execution when APP_ENV is production/staging}';

    protected $description = 'Set dummy passwords to "password" using Argon2id (defaults to legacy non-Argon2id hashes only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Command ini butuh --force saat dijalankan di luar environment local/testing.');

            return self::FAILURE;
        }

        $resetAll = (bool) $this->option('all');
        $updated = 0;
        $hasher = Hash::driver('argon2id');

        DB::transaction(function () use ($resetAll, $hasher, &$updated): void {
            $query = User::query()->select(['id', 'password']);

            if (! $resetAll) {
                $query->where(function ($builder): void {
                    $builder->whereNull('password')
                        ->orWhere('password', 'not like', '$argon2id$%');
                });
            }

            $query->chunkById(100, function ($users) use ($hasher, &$updated): void {
                foreach ($users as $user) {
                    $user->forceFill([
                        'password' => $hasher->make('password'),
                    ])->saveQuietly();
                    $updated++;
                }
            });
        });

        $this->info("Updated {$updated} user(s) to Argon2id default password.");

        return self::SUCCESS;
    }
}
