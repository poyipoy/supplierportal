@php
    $isEdit = isset($connection);
    $config = $isEdit ? ($connection->config ?? []) : [];
@endphp

<form
    method="POST"
    action="{{ $isEdit ? route('admin.sso-connections.update', $connection) : route('admin.sso-connections.store') }}"
    x-data="{ protocol: '{{ old('protocol', $isEdit ? $connection->protocol : 'saml') }}' }"
    class="tw-grid tw-gap-5 tw-max-w-2xl"
>
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="tw-grid tw-gap-1.5">
        <label for="name" class="tw-text-ui-sm tw-font-medium">Connection name</label>
        <input type="text" name="name" id="name" required maxlength="255"
            value="{{ old('name', $isEdit ? $connection->name : '') }}"
            class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Acme Corp - Azure AD">
        @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="tw-grid tw-gap-1.5">
        <label for="supplier_id" class="tw-text-ui-sm tw-font-medium">Supplier</label>
        <select name="supplier_id" id="supplier_id" required class="form-select @error('supplier_id') is-invalid @enderror">
            <option value="">Select a supplier&hellip;</option>
            @foreach($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $isEdit ? $connection->supplier_id : 0) === $supplier->id)>
                    {{ $supplier->company_name }}
                </option>
            @endforeach
        </select>
        @error('supplier_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="tw-grid tw-gap-1.5">
        <label for="domain" class="tw-text-ui-sm tw-font-medium">Email domain</label>
        <input type="text" name="domain" id="domain" required maxlength="255"
            value="{{ old('domain', $isEdit ? $connection->domain : '') }}"
            class="form-control @error('domain') is-invalid @enderror" placeholder="acme-corp.com">
        <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Users whose email ends in this domain will be offered this SSO connection.</p>
        @error('domain')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="tw-grid tw-gap-1.5">
        <label class="tw-text-ui-sm tw-font-medium">Protocol</label>
        <div class="tw-flex tw-gap-4">
            <label class="tw-flex tw-items-center tw-gap-2">
                <input type="radio" name="protocol" value="saml" x-model="protocol" class="form-check-input" {{ !$isEdit || $connection->isSaml() ? 'checked' : '' }}>
                <span>SAML 2.0</span>
            </label>
            <label class="tw-flex tw-items-center tw-gap-2">
                <input type="radio" name="protocol" value="oidc" x-model="protocol" class="form-check-input" {{ $isEdit && $connection->isOidc() ? 'checked' : '' }}>
                <span>OpenID Connect</span>
            </label>
        </div>
        @error('protocol')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <fieldset x-show="protocol === 'saml'" class="tw-grid tw-gap-4 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-p-4">
        <legend class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">SAML settings (from the supplier's IdP)</legend>

        <div class="tw-grid tw-gap-1.5">
            <label for="saml_idp_entity_id" class="tw-text-ui-sm tw-font-medium">IdP Entity ID</label>
            <input type="text" name="saml_idp_entity_id" id="saml_idp_entity_id" maxlength="255"
                value="{{ old('saml_idp_entity_id', $config['idp_entity_id'] ?? '') }}"
                class="form-control @error('saml_idp_entity_id') is-invalid @enderror">
            @error('saml_idp_entity_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="tw-grid tw-gap-1.5">
            <label for="saml_idp_sso_url" class="tw-text-ui-sm tw-font-medium">IdP SSO URL</label>
            <input type="url" name="saml_idp_sso_url" id="saml_idp_sso_url" maxlength="500"
                value="{{ old('saml_idp_sso_url', $config['idp_sso_url'] ?? '') }}"
                class="form-control @error('saml_idp_sso_url') is-invalid @enderror" placeholder="https://idp.example.com/sso/saml">
            @error('saml_idp_sso_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="tw-grid tw-gap-1.5">
            <label for="saml_idp_slo_url" class="tw-text-ui-sm tw-font-medium">IdP Single Logout URL <span class="tw-text-on-surface-variant tw-font-normal">(optional)</span></label>
            <input type="url" name="saml_idp_slo_url" id="saml_idp_slo_url" maxlength="500"
                value="{{ old('saml_idp_slo_url', $config['idp_slo_url'] ?? '') }}"
                class="form-control @error('saml_idp_slo_url') is-invalid @enderror">
            @error('saml_idp_slo_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="tw-grid tw-gap-1.5">
            <label for="saml_idp_x509_cert" class="tw-text-ui-sm tw-font-medium">IdP X.509 Certificate</label>
            <textarea name="saml_idp_x509_cert" id="saml_idp_x509_cert" rows="6"
                class="form-control tw-font-mono tw-text-ui-xs @error('saml_idp_x509_cert') is-invalid @enderror"
                placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----">{{ old('saml_idp_x509_cert') }}</textarea>
            @if($isEdit)
                <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Leave blank to keep the certificate already on file.</p>
            @endif
            @error('saml_idp_x509_cert')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </fieldset>

    <fieldset x-show="protocol === 'oidc'" class="tw-grid tw-gap-4 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-p-4">
        <legend class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">OIDC settings (from the supplier's IdP app registration)</legend>

        <div class="tw-grid tw-gap-1.5">
            <label for="oidc_issuer" class="tw-text-ui-sm tw-font-medium">Issuer URL</label>
            <input type="url" name="oidc_issuer" id="oidc_issuer" maxlength="500"
                value="{{ old('oidc_issuer', $config['issuer'] ?? '') }}"
                class="form-control @error('oidc_issuer') is-invalid @enderror" placeholder="https://login.microsoftonline.com/{tenant}/v2.0">
            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">We'll read <code>{issuer}/.well-known/openid-configuration</code> to find the rest of the endpoints.</p>
            @error('oidc_issuer')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="tw-grid tw-gap-1.5">
            <label for="oidc_client_id" class="tw-text-ui-sm tw-font-medium">Client ID</label>
            <input type="text" name="oidc_client_id" id="oidc_client_id" maxlength="255"
                value="{{ old('oidc_client_id', $config['client_id'] ?? '') }}"
                class="form-control @error('oidc_client_id') is-invalid @enderror">
            @error('oidc_client_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <div class="tw-grid tw-gap-1.5">
            <label for="oidc_client_secret" class="tw-text-ui-sm tw-font-medium">Client Secret</label>
            <input type="password" name="oidc_client_secret" id="oidc_client_secret" maxlength="1000" autocomplete="new-password"
                class="form-control @error('oidc_client_secret') is-invalid @enderror">
            @if($isEdit)
                <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Leave blank to keep the secret already on file.</p>
            @endif
            @error('oidc_client_secret')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Redirect URI to register with the IdP: <code>{{ url('/auth/sso/oidc') }}/&lt;connection-id-after-saving&gt;/callback</code></p>
    </fieldset>

    <div class="tw-flex tw-flex-col tw-gap-2">
        <label class="tw-flex tw-items-center tw-gap-2 tw-cursor-pointer">
            <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', $isEdit ? $connection->is_active : false) ? 'checked' : '' }}>
            <span class="tw-text-ui-sm">Active (allow this connection to authenticate users)</span>
        </label>
        <label class="tw-flex tw-items-center tw-gap-2 tw-cursor-pointer">
            <input type="checkbox" name="enforce_sso" value="1" class="form-check-input" {{ old('enforce_sso', $isEdit ? $connection->enforce_sso : false) ? 'checked' : '' }}>
            <span class="tw-text-ui-sm">Enforce SSO (block local password login for this domain)</span>
        </label>
        <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">Test the connection thoroughly before enabling enforcement &mdash; once enforced, users on this domain can only sign in through SSO.</p>
    </div>

    <div class="tw-flex tw-items-center tw-gap-2">
        <x-ui.button type="submit">{{ $isEdit ? 'Save Changes' : 'Create Connection' }}</x-ui.button>
        <x-ui.button :href="route('admin.sso-connections.index')" variant="ghost" type="button">Cancel</x-ui.button>
        @if($isEdit)
            <button type="button" id="testConnectionBtn" data-test-url="{{ route('admin.sso-connections.test', $connection) }}" class="btn btn-outline-secondary btn-sm tw-ms-auto">Test Connection</button>
        @endif
    </div>
    <div id="testConnectionResult" class="tw-text-ui-sm"></div>
</form>

@if($isEdit)
@push('scripts')
<script>
document.getElementById('testConnectionBtn')?.addEventListener('click', async function () {
    const btn = this;
    const resultEl = document.getElementById('testConnectionResult');
    btn.disabled = true;
    resultEl.textContent = 'Testing…';

    try {
        const response = await fetch(btn.dataset.testUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
        const data = await response.json();
        resultEl.textContent = data.message;
        resultEl.className = 'tw-text-ui-sm ' + (data.ok ? 'tw-text-success' : 'tw-text-error');
    } catch (e) {
        resultEl.textContent = 'Test request failed.';
        resultEl.className = 'tw-text-ui-sm tw-text-error';
    } finally {
        btn.disabled = false;
    }
});
</script>
@endpush
@endif
