<x-ui.form-section
    :title="__('admin.copy.supplier_business_access')"
    :description="__('admin.copy.operational_scopes_and_payment_conditions_for_supplier_accounts')"
>
    <input type="hidden" name="supplier_scopes_present" value="1">

    @php
        $currentScopes = old('supplier_scopes', isset($user) ? $user->supplierScopes()->pluck('scope')->all() : ['import']);
        if (in_array('import', (array) $currentScopes, true) && in_array('local', (array) $currentScopes, true)) {
            $initialPreset = 'both';
        } elseif (in_array('local', (array) $currentScopes, true)) {
            $initialPreset = 'local';
        } else {
            $initialPreset = 'import';
        }
        $initialPreset = old('supplier_scope_preset', $initialPreset);
        $isPaymentTermRequired = in_array($initialPreset, ['local', 'both'], true);
    @endphp

    <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
        <div>
            <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-scope-preset">
                {{ __('admin.copy.business_operations_scope') }} <span class="text-danger">*</span>
            </label>
            <select
                name="supplier_scope_preset"
                id="supplier-scope-preset"
                class="form-select @error('supplier_scope_preset') is-invalid @enderror @error('supplier_scopes') is-invalid @enderror"
                required
            >
                <option value="import" @selected($initialPreset === 'import')>{{ __('admin.copy.import_procurement_only') }}</option>
                <option value="local" @selected($initialPreset === 'local')>{{ __('admin.copy.local_invoices_only') }}</option>
                <option value="both" @selected($initialPreset === 'both')>{{ __('admin.copy.dual_access_import_procurement_local_invoices') }}</option>
            </select>

            {{-- Dynamic Scope Access Summary --}}
            <div id="scope-help-text" class="tw-mt-2.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-low tw-p-3 tw-transition-all">
                <div class="tw-flex tw-items-start tw-gap-2">
                    <x-ui.icon name="info" size="sm" class="tw-mt-0.5 tw-shrink-0 tw-text-primary" />
                    <div class="tw-min-w-0">
                        <p id="scope-description" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant tw-leading-relaxed">
                            @if($initialPreset === 'both')
                                {{ __('admin.audit_ui.scope_both') }}
                            @elseif($initialPreset === 'local')
                                {{ __('admin.audit_ui.scope_local') }}
                            @else
                                {{ __('admin.audit_ui.scope_import') }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            {{-- Synchronized hidden inputs ensuring 100% backend compatibility --}}
            <div id="hidden-scopes-container">
                @if($initialPreset === 'both')
                    <input type="hidden" name="supplier_scopes[]" value="import">
                    <input type="hidden" name="supplier_scopes[]" value="local">
                @elseif($initialPreset === 'local')
                    <input type="hidden" name="supplier_scopes[]" value="local">
                @else
                    <input type="hidden" name="supplier_scopes[]" value="import">
                @endif
            </div>

            @error('supplier_scopes')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            @error('supplier_scope_preset')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div id="payment-term-container" class="{{ $isPaymentTermRequired ? '' : 'd-none' }}">
            <label for="payment-term-days" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                {{ __('admin.copy.payment_term_days') }} <span class="text-danger">*</span>
            </label>
            <input
                type="number"
                name="payment_term_days"
                id="payment-term-days"
                class="form-control @error('payment_term_days') is-invalid @enderror"
                min="1"
                max="365"
                placeholder="{{ __('admin.copy.e_g_30') }}"
                value="{{ old('payment_term_days', isset($user) ? $user->supplier?->payment_term_days : '') }}"
                {{ $isPaymentTermRequired ? 'required' : '' }}
            >
            <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1 tw-block">
                {{ __('admin.copy.default_payment_terms_1_365_days_required_for_local_invoice_processing') }}
            </small>
            @error('payment_term_days')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</x-ui.form-section>
