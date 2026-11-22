<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
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
        $request->attributes->set('auth_security.device_hash', $deviceHash);
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

    public function currentDeviceHash(Request $request): ?string
    {
        $attr = $request->attributes->get('auth_security.device_hash');
        if (is_string($attr) && preg_match('/\A[a-f0-9]{64}\z/D', $attr) === 1) {
            return $attr;
        }

        $token = $this->validToken($request->cookie($this->cookieName()));

        return $token === null ? null : hash('sha256', $token);
    }

    public function devicesFor(User $user, ?string $currentDeviceHash): Collection
    {
        return DB::table('auth_known_devices')
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_seen_at')
            ->limit(25)
            ->get(['device_hash', 'last_ip_address', 'last_user_agent', 'first_seen_at', 'last_seen_at'])
            ->map(function (object $device) use ($currentDeviceHash): object {
                $isCurrent = $currentDeviceHash !== null
                    && hash_equals($currentDeviceHash, (string) $device->device_hash);

                return (object) [
                    'revocation_token' => $isCurrent ? null : Crypt::encryptString((string) $device->device_hash),
                    'is_current' => $isCurrent,
                    'device_label' => self::deviceLabel($device->last_user_agent),
                    'last_ip_address' => $device->last_ip_address,
                    'last_user_agent' => (string) $device->last_user_agent,
                    'first_seen_at' => Carbon::parse($device->first_seen_at),
                    'last_seen_at' => Carbon::parse($device->last_seen_at),
                ];
            });
    }

    public function revoke(User $user, string $deviceHash): bool
    {
        return DB::transaction(function () use ($user, $deviceHash): bool {
            $deleted = DB::table('auth_known_devices')
                ->where('user_id', $user->getKey())
                ->where('device_hash', $deviceHash)
                ->delete() > 0;

            if (! $deleted) {
                return false;
            }

            $sessionIds = app(RememberTokenService::class)->revokeForDevice($user, $deviceHash);

            if ($sessionIds !== []) {
                app(SessionInventoryService::class)->revokeMany($user, $sessionIds);
                app(SessionReconnectService::class)->markSessionsRevoked($sessionIds);
            }

            return true;
        });
    }

    public static function deviceLabel(?string $userAgent): string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return __('security.unknown_device');
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Safari/') && ! str_contains($ua, 'Chrome/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser !== null && $os !== null) {
            return "{$browser} on {$os}";
        }

        return $browser ?? $os ?? Str::limit($ua, 60);
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
