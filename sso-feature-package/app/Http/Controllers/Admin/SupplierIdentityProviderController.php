<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSupplierIdentityProviderRequest;
use App\Http\Requests\Admin\UpdateSupplierIdentityProviderRequest;
use App\Models\Supplier;
use App\Models\SupplierIdentityProvider;
use App\Services\Sso\OidcConnectionService;
use App\Services\Sso\SamlConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use OneLogin\Saml2\Metadata;
use OneLogin\Saml2\Settings as SamlSettings;
use Throwable;

class SupplierIdentityProviderController extends Controller
{
    public function index(): View
    {
        $connections = SupplierIdentityProvider::query()
            ->with('supplier')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('admin.sso.index', compact('connections'));
    }

    public function create(): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get(['id', 'company_name']);

        return view('admin.sso.create', compact('suppliers'));
    }

    public function store(StoreSupplierIdentityProviderRequest $request): RedirectResponse
    {
        SupplierIdentityProvider::query()->create([
            ...$request->validatedConnection(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.sso-connections.index')
            ->with('success', 'SSO connection created. It stays inactive until you test it and turn it on.');
    }

    public function edit(SupplierIdentityProvider $ssoConnection): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get(['id', 'company_name']);

        return view('admin.sso.edit', ['connection' => $ssoConnection, 'suppliers' => $suppliers]);
    }

    public function update(UpdateSupplierIdentityProviderRequest $request, SupplierIdentityProvider $ssoConnection): RedirectResponse
    {
        $ssoConnection->update($request->validatedConnection());

        return redirect()
            ->route('admin.sso-connections.index')
            ->with('success', 'SSO connection updated.');
    }

    public function destroy(SupplierIdentityProvider $ssoConnection): RedirectResponse
    {
        $ssoConnection->delete();

        return redirect()
            ->route('admin.sso-connections.index')
            ->with('success', 'SSO connection removed.');
    }

    /**
     * Download our Service Provider metadata for this connection, to hand
     * to the supplier's IT team so they can register us in their IdP.
     */
    public function samlMetadata(SupplierIdentityProvider $ssoConnection, SamlConnectionService $saml): Response
    {
        abort_unless($ssoConnection->isSaml(), 404);

        $settings = new SamlSettings($saml->settings($ssoConnection), true);
        $metadata = Metadata::builder($settings->getSPData(), false, false);
        $cert = $settings->getSPcert();
        $metadata = Metadata::addX509KeyDescriptors($metadata, $cert !== false ? $cert : null, false);

        return response($metadata, 200, [
            'Content-Type' => 'application/samlmetadata+xml',
            'Content-Disposition' => 'attachment; filename="'.$ssoConnection->domain.'-sp-metadata.xml"',
        ]);
    }

    /**
     * Lightweight server-side sanity check, not a full end-to-end login:
     * for OIDC we actually fetch the discovery document; for SAML we just
     * confirm the certificate parses. A true login still needs a live
     * round trip through the IdP.
     */
    public function testConnection(SupplierIdentityProvider $ssoConnection, OidcConnectionService $oidc): JsonResponse
    {
        if ($ssoConnection->isOidc()) {
            try {
                $discovery = $oidc->discoveryDocument($ssoConnection);

                return response()->json([
                    'ok' => true,
                    'message' => 'Discovery document loaded successfully.',
                    'details' => [
                        'authorization_endpoint' => $discovery['authorization_endpoint'] ?? null,
                        'token_endpoint' => $discovery['token_endpoint'] ?? null,
                        'jwks_uri' => $discovery['jwks_uri'] ?? null,
                    ],
                ]);
            } catch (Throwable $exception) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not load the OIDC discovery document: '.$exception->getMessage(),
                ], 422);
            }
        }

        $config = $ssoConnection->config ?? [];
        $cert = $config['idp_x509_cert'] ?? '';

        $parsed = @openssl_x509_parse($cert);

        if (! $parsed) {
            return response()->json([
                'ok' => false,
                'message' => 'The stored IdP certificate could not be parsed. Re-check it was pasted in full, including the BEGIN/END lines.',
            ], 422);
        }

        $expiresAt = isset($parsed['validTo_time_t']) ? date('Y-m-d', (int) $parsed['validTo_time_t']) : null;

        return response()->json([
            'ok' => true,
            'message' => 'Certificate parses correctly.',
            'details' => [
                'subject' => $parsed['name'] ?? null,
                'expires_at' => $expiresAt,
            ],
        ]);
    }
}
