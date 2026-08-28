<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class KnownDeviceService
{
    public function registerOrTouch(Request $request, User $user): bool
    {
        $token = $this->validToken($request->cookie($this->cookieName()))
            ?? bin2hex(random_bytes(32));

        $this->queueCookie($token);

        $deviceHash = hash('sha256', $token);
        $seenAt = now();
        $metadata = [
            'last_ip_address' => $request->ip() ?: null,
            'last_user_agent' => $this->userAgent($request),
            'last_seen_at' => $seenAt,
        ];

        try {
            $inserted = DB::table('auth_known_devices')->insertOrIgnore([
                'user_id' => $user->getKey(),
                'device_hash' => $deviceHash,
                'first_seen_at' => $seenAt,
                ...$metadata,
            ]);

            if ($inserted === 0) {
                DB::table('auth_known_devices')
                    ->where('user_id', $user->getKey())
                    ->where('device_hash', $deviceHash)
                    ->update($metadata);
            }

            return $inserted === 1;
        } catch (Throwable $exception) {
            Log::warning('Known-device registration failed.', [
                'user_id' => $user->getKey(),
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function validToken(mixed $token): ?string
    {
        return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1
            ? $token
            : null;
    }

    private function queueCookie(string $token): void
    {
        Cookie::queue(Cookie::make(
            $this->cookieName(),
            $token,
            (int) config('auth_security.known_device.lifetime_days', 400) * 24 * 60,
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));
    }

    private function cookieName(): string
    {
        return (string) config('auth_security.known_device.cookie_name', 'adasi_known_device');
    }

    private function userAgent(Request $request): ?string
    {
        $userAgent = Str::limit((string) $request->userAgent(), 512, '');

        return $userAgent === '' ? null : $userAgent;
    }
}
