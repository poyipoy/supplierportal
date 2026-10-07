@extends('layouts.app')
@section('title', __('admin.copy.add_new_user_adasi_portal'))
@section('page-title', __('admin.copy.create_user'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.page-header
        :title="__('admin.copy.add_user')"
        :description="__('admin.copy.create_an_account_with_the_approved_role_and_supplier_organization_context')"
        :eyebrow="__('admin.copy.admin_users')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> {{ __('admin.copy.back_to_user_list') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.users.store') }}" method="POST" id="userCreateForm">
        @csrf

        {{-- Section 1: Account & Security Profile --}}
        <x-ui.form-section
            :title="__('admin.copy.account_identity_and_access')"
            :description="__('admin.copy.identity_credentials_role_and_activation_status_for_this_account')"
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
                        value="{{ old('name') }}"
                        placeholder="{{ __('admin.copy.e_g_john_doe') }}"
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
                        value="{{ old('email') }}"
                        placeholder="{{ __('admin.copy.e_g_user_astradaido_co_id') }}"
                        required
                    >
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="role-select">
                        {{ __('admin.copy.portal_role') }} <span class="text-danger">*</span>
                    </label>
                    <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="">{{ __('admin.copy.select_access_role') }}</option>
                        <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>{{ __('admin.copy.admin_full_system_control') }}</option>
                        <option value="purchasing" {{ old('role') == 'purchasing' ? 'selected' : '' }}>{{ __('admin.copy.purchasing_requisitions_pos') }}</option>
                        <option value="supplier" {{ old('role') == 'supplier' ? 'selected' : '' }}>{{ __('common.final_copy.supplier_material_invoicing') }}</option>
                        <option value="qc" {{ old('role') == 'qc' ? 'selected' : '' }}>{{ __('admin.copy.quality_control_inspections_claims') }}</option>
                        <option value="finance" {{ old('role') == 'finance' ? 'selected' : '' }}>{{ __('admin.copy.finance_accounts_payable_drp') }}</option>
                        <option value="ga" {{ old('role') == 'ga' ? 'selected' : '' }}>{{ __('admin.copy.general_affairs_claims_employees') }}</option>
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tw-flex tw-items-center tw-pt-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                        <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="isActive">
                            {{ __('admin.copy.active_account_allowed_to_sign_in') }}
                        </label>
                    </div>
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password">
                        {{ __('admin.copy.initial_password') }} <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="password"
                        id="user-password"
                        class="form-control @error('password') is-invalid @enderror"
                        required
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="{{ __('admin.copy.minimum_12_characters') }}"
                    >
                    <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1 tw-block">{{ __('admin.copy.must_be_at_least_12_characters_long') }}</small>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password-confirmation">
                        {{ __('admin.copy.confirm_initial_password') }} <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="password_confirmation"
                        id="user-password-confirmation"
                        class="form-control"
                        required
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="{{ __('admin.copy.re_enter_same_password') }}"
                    >
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 2: Supplier Company Profile & Business Access (Conditional) --}}
        <div id="supplier-section" class="{{ old('role') === 'supplier' ? '' : 'd-none' }}">
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
                            value="{{ old('company_name') }}"
                            placeholder="{{ __('admin.copy.e_g_pt_daido_steel_indah') }}"
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
                            value="{{ old('category') }}"
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
                            value="{{ old('phone') }}"
                            placeholder="{{ __('admin.copy.e_g_62_21_8934567') }}"
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
                            value="{{ old('npwp') }}"
                            placeholder="{{ __('admin.copy.e_g_01_234_567_8_901_000') }}"
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
                            placeholder="{{ __('admin.copy.full_address_of_factory_or_office') }}"
                        >{{ old('address') }}</textarea>
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:right>
                <x-ui.button :href="route('admin.users.index')" variant="ghost">
                    {{ __('admin.copy.cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    {{ __('admin.copy.create_user_account') }}
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
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
    });
</script>
@endpush
