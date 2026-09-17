<?php

namespace App\Services\Sso;

use App\Models\SupplierIdentityProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;

class OidcConnectionService
{
    public function provider(SupplierIdentityProvider $provider): GenericProvider
    {
        if (! $provider->isOidc()) {
            throw new RuntimeException('Identity provider is not configured for OIDC.');
        }

        $discovery = $this->discoveryDocument($provider);
        $config = $provider->config ?? [];

        return new GenericProvider([
            'clientId' => $config['client_id'] ?? '',
            'clientSecret' => $config['client_secret'] ?? '',
            'redirectUri' => route('sso.oidc.callback', $provider),
            'urlAuthorize' => $discovery['authorization_endpoint'] ?? '',
            'urlAccessToken' => $discovery['token_endpoint'] ?? '',
            'urlResourceOwnerDetails' => $discovery['userinfo_endpoint'] ?? '',
            'scopes' => 'openid email profile',
        ]);
    }

    /**
     * Fetch (and cache) the IdP's `.well-known/openid-configuration`
     * document. Cached per-connection so a normal login doesn't re-fetch it
     * on every request.
     */
    public function discoveryDocument(SupplierIdentityProvider $provider): array
    {
        $config = $provider->config ?? [];
        $issuer = rtrim((string) ($config['issuer'] ?? ''), '/');

        if ($issuer === '') {
            throw new RuntimeException('OIDC issuer is not configured.');
        }

        return Cache::remember(
            "sso.oidc.discovery.{$provider->getKey()}",
            (int) config('sso.oidc.discovery_cache_seconds', 3600),
            function () use ($issuer) {
                $response = Http::timeout(5)->get("{$issuer}/.well-known/openid-configuration");
                $response->throw();

                return $response->json();
            }
        );
    }

    /**
     * Verify the ID token's signature against the IdP's published JWKS and
     * validate issuer/audience. Returns the decoded claims.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(SupplierIdentityProvider $provider, string $idToken): array
    {
        $discovery = $this->discoveryDocument($provider);
        $config = $provider->config ?? [];

        $jwksUri = $discovery['jwks_uri'] ?? null;

        if (! is_string($jwksUri) || $jwksUri === '') {
            throw new RuntimeException('IdP discovery document has no jwks_uri.');
        }

        $jwks = Cache::remember(
            "sso.oidc.jwks.{$provider->getKey()}",
            (int) config('sso.oidc.discovery_cache_seconds', 3600),
            fn () => Http::timeout(5)->get($jwksUri)->throw()->json()
        );

        $keys = JWK::parseKeySet($jwks);
        $decoded = JWT::decode($idToken, $keys);
        $claims = (array) $decoded;

        $expectedIssuer = rtrim((string) ($config['issuer'] ?? ''), '/');
        $actualIssuer = rtrim((string) ($claims['iss'] ?? ''), '/');

        if ($actualIssuer === '' || $actualIssuer !== $expectedIssuer) {
            throw new RuntimeException('OIDC id_token issuer mismatch.');
        }

        $audience = $claims['aud'] ?? null;
        $clientId = $config['client_id'] ?? '';
        $audienceOk = $audience === $clientId
            || (is_array($audience) && in_array($clientId, $audience, true));

        if (! $audienceOk) {
            throw new RuntimeException('OIDC id_token audience mismatch.');
        }

        return $claims;
    }
}
