<?php

namespace App\Services\Sso;

use App\Events\AuthSecurityEvent;
use App\Models\Supplier;
use App\Models\SupplierIdentityProvider;
use App\Models\User;
use App\Services\Auth\CompleteLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SsoLoginService
{
    public function __construct(private readonly CompleteLoginService $completeLogin) {}

    /**
     * @param  array{email: string|null, name?: string|null}  $claims  Already-verified attributes/claims from the IdP.
     */
    public function login(Request $request, SupplierIdentityProvider $provider, array $claims, bool $remember = false): User
    {
        $email = Str::lower(trim((string) ($claims['email'] ?? '')));

        if ($email === '' || ! str_contains($email, '@')) {
            event(new AuthSecurityEvent('sso_login_failed', metadata: ['reason' => 'missing_email_claim']));

            throw new RuntimeException('Identity provider did not return a usable email claim.');
        }

        $emailDomain = Str::lower(Str::after($email, '@'));

        if ($emailDomain !== $provider->domain) {
            event(new AuthSecurityEvent('sso_login_failed', email: $email, metadata: ['reason' => 'domain_mismatch']));

            throw new RuntimeException('Email domain does not match the configured SSO connection.');
        }

        $wasCreated = false;

        $user = DB::transaction(function () use ($provider, $email, $claims, &$wasCreated) {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => (string) ($claims['name'] ?? Str::before($email, '@')),
                    'email' => $email,
                    // Local password login stays unusable until an admin
                    // explicitly resets it; SSO users authenticate upstream
                    // with their own company IdP, never with this secret.
                    'password' => Str::password(40),
                    'role' => 'supplier',
                    'is_active' => true,
                ]);

                Supplier::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    ['company_name' => $provider->supplier->company_name ?? $user->name]
                );

                $wasCreated = true;
            }

            return $user;
        });

        if ($wasCreated) {
            event(new AuthSecurityEvent('sso_login_success', $user, metadata: ['reason' => 'jit_provisioned']));
        }

        if (! $user->is_active) {
            event(new AuthSecurityEvent('sso_login_failed', $user, metadata: ['reason' => 'account_deactivated']));

            throw new RuntimeException('This account has been deactivated.');
        }

        // Reuses the same pipeline as password login: session regeneration,
        // known-device detection, concurrent-session eviction, and the
        // built-in Login event (which logs a normal 'login_success' entry).
        $this->completeLogin->complete($request, $user, $remember);

        $provider->forceFill(['last_used_at' => now()])->save();

        event(new AuthSecurityEvent('sso_login_success', $user, metadata: ['reason' => $provider->protocol]));

        return $user;
    }
}
