<?php

namespace App\Services\Auth;

use App\Models\User;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    public const PENDING_LOGIN_KEY = 'auth.two_factor_pending';

    public const PENDING_SETUP_KEY = 'auth.two_factor_setup_secret';

    public function __construct(private readonly SessionRevocationService $revocation) {}

    public function authenticator(): Google2FA
    {
        $authenticator = new Google2FA;
        $authenticator->setKeyRegeneration(30);
        $authenticator->setWindow((int) config('auth_security.two_factor.window', 1));

        return $authenticator;
    }

    public function beginLoginChallenge(
        Request $request,
        User $user,
        bool $remember,
        ?string $intended = null,
        bool $rotateRememberCookie = false,
    ): void {
        $intended ??= $request->session()->get('url.intended', route('dashboard', absolute: false));
        $intended = $this->safeIntendedUrl($request, $intended);
        $request->session()->forget('url.intended');

        if ($rotateRememberCookie) {
            $request->attributes->set('auth_security.suppress_logout_audit', true);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            Auth::guard('web')->forgetUser();
            $request->session()->regenerate();
        }

        $request->session()->put(self::PENDING_LOGIN_KEY, [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'intended' => $intended,
            'started_at' => now()->timestamp,
        ]);
    }

    public function pendingLogin(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_LOGIN_KEY);

        return is_array($pending) ? $pending : null;
    }

    public function forgetPendingLogin(Request $request): void
    {
        $request->session()->forget(self::PENDING_LOGIN_KEY);
    }

    public function startSetup(Request $request): string
    {
        $secret = $this->authenticator()->generateSecretKey(
            (int) config('auth_security.two_factor.secret_length', 32)
        );

        $request->session()->put(self::PENDING_SETUP_KEY, Crypt::encryptString($secret));

        return $secret;
    }

    public function pendingSetupSecret(Request $request): ?string
    {
        $encrypted = $request->session()->get(self::PENDING_SETUP_KEY);

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        return Crypt::decryptString($encrypted);
    }

    public function confirmSetup(Request $request, User $user, string $code): ?array
    {
        $secret = $this->pendingSetupSecret($request);

        if ($secret === null) {
            return null;
        }

        $step = $this->authenticator()->verifyKeyNewer(
            $secret,
            $this->normalizeTotp($code),
            0,
            (int) config('auth_security.two_factor.window', 1)
        );

        if ($step === false) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        DB::transaction(function () use ($user, $secret, $step, $codes): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedUser->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => array_map(fn (string $code): string => $this->hashRecoveryCode($code), $codes),
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_step' => (int) $step,
            ])->saveQuietly();
            $this->revocation->revokeOtherSessions($lockedUser);
            $user->setRawAttributes($lockedUser->getAttributes(), true);
        });

        $request->session()->forget(self::PENDING_SETUP_KEY);
        $request->session()->put('auth_session_version', (int) $user->auth_session_version);

        return $codes;
    }

    public function verifyUserCode(User $user, string $code): ?string
    {
        $normalized = $this->normalizeCode($code);

        return DB::transaction(function () use ($user, $normalized): ?string {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser?->hasTwoFactorAuthentication()) {
                return null;
            }

            if (preg_match('/^\d{6}$/', $normalized) === 1) {
                $step = $this->authenticator()->verifyKeyNewer(
                    $lockedUser->two_factor_secret,
                    $normalized,
                    $lockedUser->two_factor_last_used_step ?? 0,
                    (int) config('auth_security.two_factor.window', 1)
                );

                if ($step !== false) {
                    $lockedUser->forceFill(['two_factor_last_used_step' => (int) $step])->saveQuietly();
                    $user->setRawAttributes($lockedUser->getAttributes(), true);

                    return 'totp';
                }
            }

            $hash = $this->hashRecoveryCode($normalized);
            $recoveryCodes = array_values($lockedUser->two_factor_recovery_codes ?? []);

            foreach ($recoveryCodes as $index => $storedHash) {
                if (is_string($storedHash) && hash_equals($storedHash, $hash)) {
                    unset($recoveryCodes[$index]);
                    $lockedUser->forceFill(['two_factor_recovery_codes' => array_values($recoveryCodes)])->saveQuietly();
                    $user->setRawAttributes($lockedUser->getAttributes(), true);

                    return 'recovery';
                }
            }

            return null;
        });
    }

    public function regenerateRecoveryCodes(Request $request, User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        DB::transaction(function () use ($user, $codes): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedUser->forceFill([
                'two_factor_recovery_codes' => array_map(fn (string $code): string => $this->hashRecoveryCode($code), $codes),
            ])->saveQuietly();
            $this->revocation->revokeOtherSessions($lockedUser);
            $user->setRawAttributes($lockedUser->getAttributes(), true);
        });

        $request->session()->put('auth_session_version', (int) $user->auth_session_version);

        return $codes;
    }

    public function disable(Request $request, User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedUser->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ])->saveQuietly();
            $this->revocation->revokeOtherSessions($lockedUser);
            $user->setRawAttributes($lockedUser->getAttributes(), true);
        });

        $request->session()->put('auth_session_version', (int) $user->auth_session_version);
    }

    public function resetByAdmin(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedUser->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ])->saveQuietly();
            $this->revocation->revokeOtherSessions($lockedUser);
            $user->setRawAttributes($lockedUser->getAttributes(), true);
        });
    }

    public function qrCodeDataUri(User $user, string $secret): string
    {
        $uri = $this->authenticator()->getQRCodeUrl(
            (string) config('app.name', 'ADASI Supplier Portal'),
            $user->email,
            $secret
        );

        return (new QRCode([
            'outputBase64' => true,
            'scale' => 6,
        ]))->render($uri);
    }

    private function generateRecoveryCodes(): array
    {
        return collect(range(1, (int) config('auth_security.two_factor.recovery_code_count', 8)))
            ->map(fn (): string => implode('-', str_split($this->randomCharacters(12), 4)))
            ->all();
    }

    private function randomCharacters(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $result = '';

        for ($index = 0; $index < $length; $index++) {
            $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $result;
    }

    private function normalizeTotp(string $code): string
    {
        return preg_replace('/\D+/', '', $code) ?? '';
    }

    private function normalizeCode(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $this->normalizeCode($code), hash('sha256', (string) config('app.key')));
    }

    private function safeIntendedUrl(Request $request, string $intended): string
    {
        if (str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        $parts = parse_url($intended);

        if (is_array($parts)
            && isset($parts['host'])
            && strcasecmp($parts['host'], $request->getHost()) === 0
            && (! isset($parts['scheme']) || strcasecmp($parts['scheme'], $request->getScheme()) === 0)
            && (! isset($parts['port']) || (int) $parts['port'] === $request->getPort())) {
            return $intended;
        }

        return route('dashboard', absolute: false);
    }
}
