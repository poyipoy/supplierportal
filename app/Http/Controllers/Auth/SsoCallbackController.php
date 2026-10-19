<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\Sso\SsoTransactionStore;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

class SsoCallbackController extends Controller
{
    public function __invoke(Request $request, SsoTransactionStore $transactions): RedirectResponse
    {
        abort_unless(config('sso.enabled'), 404);
        $request->session()->forget(['sso.verified_at', 'sso.client_id']);
        $transaction = $transactions->pull($request, $request->query('state'));

        if ($transaction === null) {
            return $this->restart($request, 'invalid_state');
        }
        $request->session()->put('sso.intended', $transactions->safeIntendedUrl($request, $transaction['intended']));

        if ($request->query->has('error')) {
            return $this->restart($request, 'authorization_denied', __('auth.sso.access_denied'));
        }
        $code = $request->query('code');
        if (! $request->user()->isAuthenticationEligible()
            || ! is_string($code) || $code === '' || strlen($code) > 8192
            || $transaction['client_id'] !== (string) config('sso.client_id')
            || $transaction['redirect_uri'] !== route('sso.callback')) {
            return $this->restart($request, 'invalid_callback');
        }

        try {
            // Issuance, identity checks and revocation share the portal database.
            // On failure a rollback prevents a newly minted active token surviving.
            $matches = DB::transaction(function () use ($request, $transaction, $code): bool {
                $exchange = app(ServerRequestInterface::class)
                    ->withMethod('POST')
                    ->withQueryParams([])
                    ->withParsedBody([
                        'grant_type' => 'authorization_code',
                        'client_id' => $transaction['client_id'],
                        'redirect_uri' => $transaction['redirect_uri'],
                        'code' => $code,
                        'code_verifier' => $transaction['verifier'],
                    ]);
                $response = app(AuthorizationServer::class)->respondToAccessTokenRequest($exchange, app(ResponseInterface::class));
                $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                if (! is_string($payload['access_token'] ?? null)) {
                    throw new RuntimeException('SSO exchange did not issue a token.');
                }

                $publicKey = str_replace('\\n', "\n", config('passport.public_key') ?? '');
                $claims = JWT::decode($payload['access_token'], new Key($publicKey ?: 'file://'.Passport::keyPath('oauth-public.key'), 'RS256'));
                $tokenId = $claims->jti ?? null;
                if (! is_string($tokenId) || ! is_numeric($claims->exp ?? null)
                    || $claims->exp <= now()->timestamp) { // biz-time:ignore JWT expiry is an instant
                    throw new RuntimeException('SSO token claims are invalid.');
                }
                $token = Passport::token()->newQuery()->whereKey($tokenId)->lockForUpdate()->firstOrFail();
                if ((string) $token->client_id !== $transaction['client_id'] || $token->revoked) {
                    throw new RuntimeException('SSO token binding is invalid.');
                }
                $audience = is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];
                $matches = (string) ($claims->sub ?? '') === (string) $request->user()->getAuthIdentifier()
                    && (string) $token->user_id === (string) $request->user()->getAuthIdentifier()
                    && $audience === [$transaction['client_id']];

                // Use the installed Passport bridge repositories (13.x public repositories changed).
                foreach (Passport::refreshToken()->newQuery()->where('access_token_id', $tokenId)->pluck('id') as $refreshId) {
                    app(RefreshTokenRepository::class)->revokeRefreshToken($refreshId);
                }
                app(AccessTokenRepository::class)->revokeAccessToken($tokenId);

                return $matches;
            });
        } catch (Throwable) {
            // Do not report exception messages/objects: they can carry codes and tokens.
            return $this->restart($request, 'exchange_failed');
        }

        if (! $matches) {
            event(new AuthSecurityEvent('sso_subject_mismatch', $request->user(), metadata: ['client_id' => (string) config('sso.client_id'), 'reason' => 'identity_mismatch']));
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('auth.feedback.sso_restart'));
        }

        $request->session()->put([
            'sso.verified_at' => now()->timestamp, // biz-time:ignore session verification is an instant
            'sso.client_id' => $transaction['client_id'],
        ]);
        $request->session()->forget('sso.intended');
        event(new AuthSecurityEvent('sso_authorization_completed', $request->user(), metadata: ['client_id' => $transaction['client_id']]));

        return redirect()->to($transactions->safeIntendedUrl($request, $transaction['intended']));
    }

    private function restart(Request $request, string $reason, ?string $message = null): RedirectResponse
    {
        event(new AuthSecurityEvent('sso_state_rejected', $request->user(), metadata: ['client_id' => (string) config('sso.client_id'), 'reason' => $reason]));

        return redirect()->route('login')->with('status', $message ?? __('auth.feedback.sso_restart'));
    }
}
