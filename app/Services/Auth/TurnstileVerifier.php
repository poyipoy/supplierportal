<?php

namespace App\Services\Auth;

use App\Enums\TurnstileStatus;
use App\Events\AuthSecurityEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class TurnstileVerifier
{
    public function configured(): bool
    {
        return filled(config('auth_security.turnstile.site_key'))
            && filled(config('auth_security.turnstile.secret_key'));
    }

    public function verify(Request $request): TurnstileStatus
    {
        if (! $this->configured()) {
            return TurnstileStatus::Disabled;
        }

        $token = trim((string) $request->input('cf-turnstile-response'));

        if ($token === '') {
            return TurnstileStatus::Invalid;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('auth_security.turnstile.timeout_seconds', 3))
                ->post((string) config('auth_security.turnstile.verify_url'), [
                    'secret' => config('auth_security.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);

            if ($response->serverError()) {
                event(new AuthSecurityEvent('captcha_provider_error', email: $request->string('email')->toString(), metadata: [
                    'provider_status' => $response->status(),
                ]));

                return TurnstileStatus::ProviderError;
            }

            return $response->successful() && $response->json('success') === true
                ? TurnstileStatus::Passed
                : TurnstileStatus::Invalid;
        } catch (ConnectionException $exception) {
            event(new AuthSecurityEvent('captcha_provider_error', email: $request->string('email')->toString(), metadata: [
                'provider_status' => 'connection_error',
            ]));

            return TurnstileStatus::ProviderError;
        } catch (Throwable $exception) {
            return TurnstileStatus::Invalid;
        }
    }
}
