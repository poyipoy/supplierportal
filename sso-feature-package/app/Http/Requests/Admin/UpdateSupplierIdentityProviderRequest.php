<?php

namespace App\Http\Requests\Admin;

use App\Models\SupplierIdentityProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierIdentityProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $connectionId = $this->route('ssoConnection')?->getKey();

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:255'],
            'protocol' => ['required', Rule::in(SupplierIdentityProvider::PROTOCOLS)],
            'domain' => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
                Rule::unique('supplier_identity_providers', 'domain')->ignore($connectionId),
            ],
            'is_active' => ['nullable', 'boolean'],
            'enforce_sso' => ['nullable', 'boolean'],

            'saml_idp_entity_id' => ['required_if:protocol,saml', 'nullable', 'string', 'max:255'],
            'saml_idp_sso_url' => ['required_if:protocol,saml', 'nullable', 'url', 'max:500'],
            'saml_idp_slo_url' => ['nullable', 'url', 'max:500'],
            // Blank on update means "keep the existing certificate/secret".
            'saml_idp_x509_cert' => ['nullable', 'string'],

            'oidc_issuer' => ['required_if:protocol,oidc', 'nullable', 'url', 'max:500'],
            'oidc_client_id' => ['required_if:protocol,oidc', 'nullable', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedConnection(): array
    {
        $data = $this->validated();
        $existing = $this->route('ssoConnection')?->config ?? [];

        $config = $data['protocol'] === SupplierIdentityProvider::PROTOCOL_SAML ? [
            'idp_entity_id' => $data['saml_idp_entity_id'],
            'idp_sso_url' => $data['saml_idp_sso_url'],
            'idp_slo_url' => $data['saml_idp_slo_url'] ?? null,
            'idp_x509_cert' => filled($data['saml_idp_x509_cert'] ?? null)
                ? $data['saml_idp_x509_cert']
                : ($existing['idp_x509_cert'] ?? ''),
        ] : [
            'issuer' => rtrim($data['oidc_issuer'], '/'),
            'client_id' => $data['oidc_client_id'],
            'client_secret' => filled($data['oidc_client_secret'] ?? null)
                ? $data['oidc_client_secret']
                : ($existing['client_secret'] ?? ''),
        ];

        return [
            'supplier_id' => $data['supplier_id'],
            'name' => $data['name'],
            'protocol' => $data['protocol'],
            'domain' => strtolower($data['domain']),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'enforce_sso' => (bool) ($data['enforce_sso'] ?? false),
            'config' => $config,
        ];
    }
}
