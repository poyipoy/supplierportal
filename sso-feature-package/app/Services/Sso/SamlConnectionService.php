<?php

namespace App\Services\Sso;

use App\Models\SupplierIdentityProvider;
use OneLogin\Saml2\Auth as SamlAuth;
use RuntimeException;

class SamlConnectionService
{
    /**
     * Build a ready-to-use SAML Auth instance for this connection.
     */
    public function auth(SupplierIdentityProvider $provider): SamlAuth
    {
        if (! $provider->isSaml()) {
            throw new RuntimeException('Identity provider is not configured for SAML.');
        }

        $this->ensureNativeSession();

        return new SamlAuth($this->settings($provider));
    }

    /**
     * The onelogin/php-saml settings array for this connection. Also used
     * (with $spValidationOnly) to generate our SP metadata for the admin to
     * hand to the supplier's IT team.
     */
    public function settings(SupplierIdentityProvider $provider): array
    {
        $config = $provider->config ?? [];

        return [
            'strict' => (bool) config('sso.saml.strict', true),
            'debug' => false,
            'sp' => [
                'entityId' => config('sso.saml.sp_entity_id'),
                'assertionConsumerService' => [
                    'url' => route('sso.saml.acs', $provider),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => route('sso.saml.sls', $provider),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert' => (string) config('sso.saml.sp_x509_cert', ''),
                'privateKey' => (string) config('sso.saml.sp_private_key', ''),
            ],
            'idp' => [
                'entityId' => $config['idp_entity_id'] ?? '',
                'singleSignOnService' => [
                    'url' => $config['idp_sso_url'] ?? '',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => $config['idp_slo_url'] ?? ($config['idp_sso_url'] ?? ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $config['idp_x509_cert'] ?? '',
            ],
            'security' => [
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => false,
            ],
        ];
    }

    /**
     * onelogin/php-saml reads and writes AuthnRequestID / replay-protection
     * state directly on the native PHP session ($_SESSION), independent of
     * Laravel's own session driver. Starting it here does not conflict with
     * Laravel's session cookie (it uses a different cookie name) and is
     * required for SAML's InResponseTo validation to work under `strict`.
     */
    private function ensureNativeSession(): void
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            session_start();
        }
    }
}
