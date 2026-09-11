@extends('layouts.app')
@section('title', 'Edit User - ADASI Portal')
@section('page-title', 'Edit User')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.breadcrumb :items="['Users' => route('admin.users.index'), $user->name => null]" />

    <x-ui.page-header
        :title="$user->name"
        description="Update account identity, role access, status, credentials, and supplier organization details."
        eyebrow="Admin Users"
    >
        <x-slot:meta>
            <x-ui.status-chip :tone="$user->is_active ? 'success' : 'neutral'">
                {{ $user->is_active ? 'Active' : 'Inactive' }}
            </x-ui.status-chip>
            <x-ui.status-chip tone="info">
                {{ $user->role === 'qc' ? 'QC' : ucfirst($user->role) }}
            </x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> Back to Users
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.users.update', $user) }}" method="POST" id="userEditForm">
        @csrf
        @method('PUT')

        {{-- Section 1: Account Identity --}}
        <x-ui.form-section
            title="Account Identity"
            description="Display name and email address used for portal communications."
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-name">
                        Full Name <span class="text-danger">*</span>
                    </label>
                    <input
                        type="text"
                        name="name"
                        id="user-name"
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name', $user->name) }}"
                        required
                    >
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-email">
                        Email Address <span class="text-danger">*</span>
                    </label>
                    <input
                        type="email"
                        name="email"
                        id="user-email"
                        class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email', $user->email) }}"
                        required
                    >
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 2: Role & Activation Status --}}
        <x-ui.form-section
            title="Access Role and Activation"
            description="Modifying role or status will automatically invalidate existing active sessions for security."
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="role-select">
                        Portal Role <span class="text-danger">*</span>
                    </label>
                    <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="">-- Select Access Role --</option>
                        <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Admin (Full System Control)</option>
                        <option value="purchasing" {{ old('role', $user->role) == 'purchasing' ? 'selected' : '' }}>Purchasing (Requisitions &amp; POs)</option>
                        <option value="supplier" {{ old('role', $user->role) == 'supplier' ? 'selected' : '' }}>Supplier (Material &amp; Invoicing)</option>
                        <option value="qc" {{ old('role', $user->role) == 'qc' ? 'selected' : '' }}>Quality Control (Inspections &amp; Claims)</option>
                        <option value="accounting" {{ in_array(old('role', $user->role), ['accounting', 'finance']) ? 'selected' : '' }}>Finance and Accounting (Local Invoices)</option>
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tw-flex tw-items-center tw-pt-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="isActive">
                            Active Account (Allowed to sign in)
                        </label>
                    </div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 3: Password Update --}}
        <x-ui.form-section
            title="Credential Management"
            description="Leave both password inputs blank if you do not wish to reset the user's password."
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password">
                        New Password
                    </label>
                    <input
                        type="password"
                        name="password"
                        id="user-password"
                        class="form-control @error('password') is-invalid @enderror"
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="Leave blank to retain current password"
                    >
                    <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1 tw-block">Minimum 12 characters if updating.</small>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password-confirmation">
                        Confirm New Password
                    </label>
                    <input
                        type="password"
                        name="password_confirmation"
                        id="user-password-confirmation"
                        class="form-control"
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="Re-enter new password"
                    >
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 4: Supplier Profile & Business Access (Conditional) --}}
        <div id="supplier-section" class="{{ old('role', $user->role) === 'supplier' ? '' : 'd-none' }}">
            @include('admin.users.supplier-scopes')

            <x-ui.form-section
                title="Supplier Organization"
                description="Company identity, contact details, and material category."
            >
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-company-name">
                            Company Legal Name (PT / CV / Corp) <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="company_name"
                            id="supplier-company-name"
                            class="form-control @error('company_name') is-invalid @enderror"
                            value="{{ old('company_name', $user->supplier->company_name ?? '') }}"
                        >
                        @error('company_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-category">
                            Material Supply Category <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="category"
                            id="supplier-category"
                            class="form-control @error('category') is-invalid @enderror"
                            value="{{ old('category', $user->supplier->category ?? '') }}"
                            placeholder="e.g. Special Steel, Tool Steel, Rods"
                        >
                        @error('category')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-phone">
                            Phone / Contact Number <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="phone"
                            id="supplier-phone"
                            class="form-control @error('phone') is-invalid @enderror"
                            value="{{ old('phone', $user->supplier->phone ?? '') }}"
                        >
                        @error('phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-npwp">
                            Tax ID / NPWP <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="npwp"
                            id="supplier-npwp"
                            class="form-control @error('npwp') is-invalid @enderror"
                            value="{{ old('npwp', $user->supplier->npwp ?? '') }}"
                        >
                        @error('npwp')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="sm:tw-col-span-2">
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-address">
                            Registered Office Address <span class="text-danger">*</span>
                        </label>
                        <textarea
                            name="address"
                            id="supplier-address"
                            class="form-control @error('address') is-invalid @enderror"
                            rows="3"
                        >{{ old('address', $user->supplier->address ?? '') }}</textarea>
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:left>
                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                    Last updated {{ $user->updated_at?->format('d M Y, H:i') ?? '-' }}
                </span>
            </x-slot:left>
            <x-slot:right>
                <x-ui.button :href="route('admin.users.index')" variant="ghost">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    Save User Changes
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>

    {{-- Section 5: Security / Two-Factor Authentication Reset (if applicable) --}}
    @if ($user->hasTwoFactorAuthentication() && $user->id !== auth()->id())
        <div class="tw-border tw-border-error/40 tw-rounded-ui-sm tw-bg-surface-low tw-p-5">
            <div class="tw-flex tw-flex-col tw-gap-4 md:tw-flex-row md:tw-items-center md:tw-justify-between">
                <div>
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-error">Two-Factor Authentication Security</h3>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                        Reset MFA only after confirming the user has permanently lost access to their authenticator device and all recovery keys.
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.users.two-factor.destroy', $user) }}" class="mfa-reset-form tw-shrink-0">
                    @csrf

                    @method('DELETE')
                    <x-ui.button type="button" variant="danger" class="btn-reset-mfa" size="sm">
                        <x-ui.icon name="shield-alert" size="sm" /> Reset 2FA Security
                    </x-ui.button>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const roleSelect = document.getElementById('role-select');
        const supplierSection = document.getElementById('supplier-section');
        const scopePresetSelect = document.getElementById('supplier-scope-preset');
        const paymentTermContainer = document.getElementById('payment-term-container');
        const paymentTermInput = document.getElementById('payment-term-days');
        const scopeDescription = document.getElementById('scope-description');
        const hiddenScopesContainer = document.getElementById('hidden-scopes-container');

        const scopeDescriptions = {
            import: 'Access: Material Requisitions (PR), Quotations, Purchase Orders (PO), and QC Inspections.',
            local: 'Access: Local Invoice Submissions, Physical Document Verification, and Payment Processing.',
            both: 'Full Access: Both Import Material Procurement and Local Invoice Processing.'
        };

        const requiredSupplierFields = [
            'company_name',
            'category',
            'phone',
            'npwp',
            'address'
        ];

        function syncHiddenScopes(preset) {
            if (!hiddenScopesContainer) return;
            hiddenScopesContainer.innerHTML = '';
            if (preset === 'both') {
                hiddenScopesContainer.innerHTML = '<input type="hidden" name="supplier_scopes[]" value="import">' +
                    '<input type="hidden" name="supplier_scopes[]" value="local">';
            } else if (preset === 'local') {
                hiddenScopesContainer.innerHTML = '<input type="hidden" name="supplier_scopes[]" value="local">';
            } else {
                hiddenScopesContainer.innerHTML = '<input type="hidden" name="supplier_scopes[]" value="import">';
            }
        }

        function togglePaymentTerm() {
            if (!scopePresetSelect || !paymentTermContainer) return;
            const isSupplier = roleSelect ? roleSelect.value === 'supplier' : true;
            const preset = scopePresetSelect.value;
            const requiresPaymentTerm = preset === 'local' || preset === 'both';

            if (scopeDescription && scopeDescriptions[preset]) {
                scopeDescription.textContent = scopeDescriptions[preset];
            }

            syncHiddenScopes(preset);

            if (requiresPaymentTerm && isSupplier) {
                paymentTermContainer.classList.remove('d-none');
                if (paymentTermInput) {
                    paymentTermInput.removeAttribute('disabled');
                    paymentTermInput.setAttribute('required', 'required');
                }
            } else {
                paymentTermContainer.classList.add('d-none');
                if (paymentTermInput) {
                    paymentTermInput.removeAttribute('required');
                    if (!isSupplier) {
                        paymentTermInput.setAttribute('disabled', 'disabled');
                    }
                }
            }
        }

        function toggleSupplierFields() {
            if (!roleSelect || !supplierSection) return;
            const isSupplier = roleSelect.value === 'supplier';
            const allSupplierElements = supplierSection.querySelectorAll('input, select, textarea');

            if (isSupplier) {
                supplierSection.classList.remove('d-none');
                allSupplierElements.forEach(el => {
                    el.removeAttribute('disabled');
                    if (requiredSupplierFields.includes(el.name)) {
                        el.setAttribute('required', 'required');
                    }
                });
                togglePaymentTerm();
            } else {
                supplierSection.classList.add('d-none');
                allSupplierElements.forEach(el => {
                    el.setAttribute('disabled', 'disabled');
                    el.removeAttribute('required');
                });
            }
        }

        if (roleSelect) {
            roleSelect.addEventListener('change', toggleSupplierFields);
        }
        if (scopePresetSelect) {
            scopePresetSelect.addEventListener('change', togglePaymentTerm);
        }

        toggleSupplierFields();

        document.querySelector('.btn-reset-mfa')?.addEventListener('click', function () {
            const form = this.closest('form');
            AdasiAlert.confirmDanger({
                title: @json('Reset Two-Factor Authentication?'),
                text: @json('The user will be required to configure 2FA again upon their next sign-in.'),
                confirmText: @json('Yes, Reset 2FA'),
                cancelText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        });
    });
</script>
@endpush
