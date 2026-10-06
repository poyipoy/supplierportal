@extends('layouts.app')
@section('title', __('admin.copy.edit_user_adasi_portal'))
@section('page-title', __('admin.copy.edit_user'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.breadcrumb :items="['Users' => route('admin.users.index'), $user->name => null]" />

    <x-ui.page-header
        :title="$user->name"
        :description="__('admin.copy.update_account_identity_role_access_status_credentials_and_supplier_organization_details')"
        :eyebrow="__('admin.copy.admin_users')"
    >
        <x-slot:meta>
            <x-ui.status-chip :tone="$user->is_active ? 'success' : 'neutral'">
                {{ $user->is_active ? __('admin.copy.active') : __('admin.copy.inactive') }}
            </x-ui.status-chip>
            <x-ui.status-chip tone="info">
                {{ match ($user->role) {
                    'admin' => __('admin.copy.role_admin'),
                    'purchasing' => __('admin.copy.role_purchasing'),
                    'supplier' => __('admin.copy.role_supplier'),
                    'qc' => __('admin.copy.role_qc'),
                    'finance' => __('admin.copy.role_finance'),
                    'accounting' => __('admin.copy.role_accounting'),
                    'ga' => __('admin.copy.role_ga'),
                    default => __('admin.copy.role_unknown'),
                } }}
            </x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> {{ __('admin.copy.back_to_users') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.users.update', $user) }}" method="POST" id="userEditForm">
        @csrf
        @method('PUT')

        {{-- Section 1: Account Identity --}}
        <x-ui.form-section
            :title="__('admin.copy.account_identity')"
            :description="__('admin.copy.display_name_and_email_address_used_for_portal_communications')"
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-name">
                        {{ __('admin.copy.full_name') }} <span class="text-danger">*</span>
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
                        {{ __('admin.copy.email_address') }} <span class="text-danger">*</span>
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
            :title="__('admin.copy.access_role_and_activation')"
            :description="__('admin.copy.modifying_role_or_status_will_automatically_invalidate_existing_active_sessions_for_security')"
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="role-select">
                        {{ __('admin.copy.portal_role') }} <span class="text-danger">*</span>
                    </label>
                    <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="">{{ __('admin.copy.select_access_role') }}</option>
                        <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>{{ __('admin.copy.admin_full_system_control') }}</option>
                        <option value="purchasing" {{ old('role', $user->role) == 'purchasing' ? 'selected' : '' }}>{{ __('admin.copy.purchasing_requisitions_pos') }}</option>
                        <option value="supplier" {{ old('role', $user->role) == 'supplier' ? 'selected' : '' }}>{{ __('common.final_copy.supplier_material_invoicing') }}</option>
                        <option value="qc" {{ old('role', $user->role) == 'qc' ? 'selected' : '' }}>{{ __('admin.copy.quality_control_inspections_claims') }}</option>
                        <option value="finance" {{ old('role', $user->role) == 'finance' ? 'selected' : '' }}>{{ __('admin.copy.finance_accounts_payable_drp') }}</option>
                        <option value="ga" {{ old('role', $user->role) == 'ga' ? 'selected' : '' }}>{{ __('admin.copy.general_affairs_claims_employees') }}</option>
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tw-flex tw-items-center tw-pt-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="isActive">
                            {{ __('admin.copy.active_account_allowed_to_sign_in') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 3: Password Update --}}
        <x-ui.form-section
            :title="__('admin.copy.credential_management')"
            :description="__('admin.copy.leave_both_password_inputs_blank_if_you_do_not_wish_to_reset_the_user_s_password')"
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password">
                        {{ __('admin.copy.new_password') }}
                    </label>
                    <input
                        type="password"
                        name="password"
                        id="user-password"
                        class="form-control @error('password') is-invalid @enderror"
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="{{ __('admin.copy.leave_blank_to_retain_current_password') }}"
                    >
                    <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1 tw-block">{{ __('admin.copy.minimum_12_characters_if_updating') }}</small>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password-confirmation">
                        {{ __('admin.copy.confirm_new_password') }}
                    </label>
                    <input
                        type="password"
                        name="password_confirmation"
                        id="user-password-confirmation"
                        class="form-control"
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="{{ __('admin.copy.re_enter_new_password') }}"
                    >
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 4: Supplier Profile & Business Access (Conditional) --}}
        <div id="supplier-section" class="{{ old('role', $user->role) === 'supplier' ? '' : 'd-none' }}">
            @include('admin.users.supplier-scopes')

            <x-ui.form-section
                :title="__('admin.copy.supplier_organization')"
                :description="__('admin.copy.company_identity_contact_details_and_material_category')"
            >
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-company-name">
                            {{ __('admin.copy.company_legal_name_pt_cv_corp') }} <span class="text-danger">*</span>
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
                            {{ __('admin.copy.material_supply_category') }} <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="category"
                            id="supplier-category"
                            class="form-control @error('category') is-invalid @enderror"
                            value="{{ old('category', $user->supplier->category ?? '') }}"
                            placeholder="{{ __('admin.copy.e_g_special_steel_tool_steel_rods') }}"
                        >
                        @error('category')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-phone">
                            {{ __('admin.copy.phone_contact_number') }} <span class="text-danger">*</span>
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
                            {{ __('admin.copy.tax_id_npwp') }} <span class="text-danger">*</span>
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
                            {{ __('admin.copy.registered_office_address') }} <span class="text-danger">*</span>
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
                    {{ __('common.final_review.last_updated', ['date' => $user->updated_at ? $regionalFormatter->timestamp($user->updated_at, 'datetime_comma') : '-']) }}
                </span>
            </x-slot:left>
            <x-slot:right>
                <x-ui.button :href="route('admin.users.index')" variant="ghost">
                    {{ __('admin.copy.cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    {{ __('admin.copy.save_user_changes') }}
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>

    {{-- Section 5: Security / Two-Factor Authentication Reset (if applicable) --}}
    @if ($user->hasTwoFactorAuthentication() && $user->id !== auth()->id())
        <div class="tw-border tw-border-error/40 tw-rounded-ui-sm tw-bg-surface-low tw-p-5">
            <div class="tw-flex tw-flex-col tw-gap-4 md:tw-flex-row md:tw-items-center md:tw-justify-between">
                <div>
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-error">{{ __('admin.copy.two_factor_authentication_security') }}</h3>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('admin.copy.reset_mfa_only_after_confirming_the_user_has_permanently_lost_access_to_their_authenticator_device_a') }}
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.users.two-factor.destroy', $user) }}" class="mfa-reset-form tw-shrink-0">
                    @csrf

                    @method('DELETE')
                    <x-ui.button type="button" variant="danger" class="btn-reset-mfa" size="sm">
                        <x-ui.icon name="shield-alert" size="sm" /> {{ __('admin.copy.reset_2fa_security') }}
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
            import: @json(__('admin.copy.access_material_requisitions_pr_quotations_purchase_orders_po_and_qc_inspections')),
            local: @json(__('admin.copy.access_local_invoice_submissions_physical_document_verification_and_payment_processing')),
            both: @json(__('admin.copy.full_access_both_import_material_procurement_and_local_invoice_processing'))
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
                title: @json(__('admin.copy.reset_two_factor_authentication')),
                text: @json(__('admin.copy.the_user_will_be_required_to_configure_2fa_again_upon_their_next_sign_in')),
                confirmText: @json(__('admin.copy.yes_reset_two_factor_authentication')),
                cancelText: @json(__('admin.copy.cancel'))
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        });
    });
</script>
@endpush
