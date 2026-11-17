<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class PasswordHistoryService
{
    public function matchesRecent(User $user, #[SensitiveParameter] string $plainPassword): bool
    {
        $historyCount = $this->historyCount();

        if ($historyCount < 1) {
            return false;
        }

        if (is_string($user->password) && $user->password !== '' && Hash::check($plainPassword, $user->password)) {
            return true;
        }

        $recentHashes = DB::table('auth_password_histories')
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit($historyCount)
            ->pluck('password_hash');

        foreach ($recentHashes as $storedHash) {
            if (is_string($storedHash)
                && $storedHash !== ''
                && $storedHash !== $user->password
                && Hash::check($plainPassword, $storedHash)) {
                return true;
            }
        }

        return false;
    }

    public function seedIfEmpty(User $user): void
    {
        if (! is_string($user->password) || $user->password === '') {
            return;
        }

        $exists = DB::table('auth_password_histories')
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $exists) {
            $this->record($user, $user->password);
        }
    }

    public function record(User $user, string $passwordHash): void
    {
        $historyCount = $this->historyCount();

        if ($historyCount < 1 || $passwordHash === '') {
            return;
        }

        DB::table('auth_password_histories')->insert([
            'user_id' => $user->getKey(),
            'password_hash' => $passwordHash,
            'created_at' => now(),
        ]);

        $keepIds = DB::table('auth_password_histories')
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit($historyCount)
            ->pluck('id')
            ->all();

        if ($keepIds !== []) {
            DB::table('auth_password_histories')
                ->where('user_id', $user->getKey())
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }

    public function historyCount(): int
    {
        return max(0, (int) config('auth_security.password.history_count', 5));
    }
}
