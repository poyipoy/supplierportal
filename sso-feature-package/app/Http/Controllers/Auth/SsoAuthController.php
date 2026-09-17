<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SupplierIdentityProvider;
use App\Services\Sso\OidcConnectionService;
use App\Services\Sso\SamlConnectionService;
use App\Services\Sso\SsoDomainResolver;
use App\Services\Sso\SsoLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SsoAuthController extends Controller
{
    /**
     * "Enter your work email" screen — the domain-discovery step before we
     * know which IdP (if any) to send the person to.
     */
    public function show(): View
    {
        return view('auth.sso-login');
    }

    public function redirect(
        Request $request,
        SsoDomainResolver $resolver,
        SamlConnectionService $saml,
        OidcConnectionService $oidc,
    ): RedirectResponse {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $email = Str::lower(trim($request->string('email')->toString()));
        $provider = $resolver->resolve($email);

        if (! $provider) {
            throw ValidationException::withMessages([
                'email' => "We couldn't find a company SSO connection for that email address. Use your portal password instead, or contact your administrator.",
            ]);
        }

        $request->session()->put('sso_login_remember', $request->boolean('remember'));

        if ($provider->isSaml()) {
            $auth = $saml->auth($provider);
            $url = $auth->login(null, [], false, false, true);

            return redirect()->away($url);
        }

        $client = $oidc->provider($provider);
        $state = Str::random(40);
        $request->session()->put('sso_oidc_state', $state);

        return redirect()->away($client->getAuthorizationUrl(['state' => $state]));
    }

    /**
     * The {ssoConnection} parameter name must match this URI segment name
     * exactly (see routes/auth.php) for Laravel's implicit route model
     * binding to resolve it.
     */
    public function samlAcs(
        Request $request,
        SupplierIdentityProvider $ssoConnection,
        SamlConnectionService $saml,
        SsoLoginService $login,
    ): RedirectResponse {
        $auth = $saml->auth($ssoConnection);
        $auth->processResponse();

        if ($auth->getErrors() !== [] || ! $auth->isAuthenticated()) {
            Log::warning('SAML SSO authentication failed.', [
                'provider_id' => $ssoConnection->getKey(),
                'errors' => $auth->getErrors(),
                'last_error_reason' => $auth->getLastErrorReason(),
            ]);

            return redirect()->route('login')->with('status', 'Company SSO sign-in failed. Please try again or contact your administrator.');
        }

        $attributes = $auth->getAttributes();

        $claims = [
            'email' => $attributes['email'][0]
                ?? $attributes['emailaddress'][0]
                ?? $attributes['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'][0]
                ?? $auth->getNameId(),
            'name' => $attributes['name'][0] ?? $attributes['displayname'][0] ?? null,
        ];

        return $this->finishLogin($request, $ssoConnection, $claims, $login);
    }

    /**
     * Single Logout callback. We don't try to propagate logout back to the
     * IdP session here — just land the browser back on the login screen.
     */
    public function samlSls(): RedirectResponse
    {
        return redirect()->route('login');
    }

    public function oidcCallback(
        Request $request,
        SupplierIdentityProvider $ssoConnection,
        OidcConnectionService $oidc,
        SsoLoginService $login,
    ): RedirectResponse {
        $expectedState = $request->session()->pull('sso_oidc_state');

        if (! $request->filled('code') || ! $request->filled('state') || $request->string('state')->toString() !== $expectedState) {
            return redirect()->route('login')->with('status', 'Company SSO sign-in failed. Please try again.');
        }

        try {
            $client = $oidc->provider($ssoConnection);
            $token = $client->getAccessToken('authorization_code', [
                'code' => $request->string('code')->toString(),
            ]);

            $idToken = $token->getValues()['id_token'] ?? null;

            if (! is_string($idToken) || $idToken === '') {
                throw new \RuntimeException('IdP response did not include an id_token.');
            }

            $claims = $oidc->verifyIdToken($ssoConnection, $idToken);
        } catch (Throwable $exception) {
            Log::warning('OIDC SSO authentication failed.', [
                'provider_id' => $ssoConnection->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return redirect()->route('login')->with('status', 'Company SSO sign-in failed. Please try again or contact your administrator.');
        }

        return $this->finishLogin($request, $ssoConnection, [
            'email' => $claims['email'] ?? null,
            'name' => $claims['name'] ?? null,
        ], $login);
    }

    private function finishLogin(Request $request, SupplierIdentityProvider $ssoConnection, array $claims, SsoLoginService $login): RedirectResponse
    {
        $remember = (bool) $request->session()->pull('sso_login_remember', false);

        try {
            $login->login($request, $ssoConnection, $claims, $remember);
        } catch (Throwable $exception) {
            Log::warning('SSO login could not be completed.', [
                'provider_id' => $ssoConnection->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return redirect()->route('login')->with('status', 'Company SSO sign-in failed. Please try again or contact your administrator.');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
