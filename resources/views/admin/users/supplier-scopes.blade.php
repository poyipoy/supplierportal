<x-ui.form-section
    title="Supplier Business Access"
    description="Operational scopes and payment conditions for supplier accounts."
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
                Business Operations Scope <span class="text-danger">*</span>
            </label>
            <select
                name="supplier_scope_preset"
                id="supplier-scope-preset"
                class="form-select @error('supplier_scope_preset') is-invalid @enderror @error('supplier_scopes') is-invalid @enderror"
                required
            >
                <option value="import" @selected($initialPreset === 'import')>Import Procurement Only</option>
                <option value="local" @selected($initialPreset === 'local')>Local Invoices Only</option>
                <option value="both" @selected($initialPreset === 'both')>Dual Access (Import Procurement &amp; Local Invoices)</option>
            </select>

            {{-- Dynamic Scope Access Summary --}}
            <div id="scope-help-text" class="tw-mt-2.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-low tw-p-3 tw-transition-all">
                <div class="tw-flex tw-items-start tw-gap-2">
                    <x-ui.icon name="info" size="sm" class="tw-mt-0.5 tw-shrink-0 tw-text-primary" />
                    <div class="tw-min-w-0">
                        <p id="scope-description" class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant tw-leading-relaxed">
                            @if($initialPreset === 'both')
                                Full Access: Both Import Material Procurement and Local Invoice Processing.
                            @elseif($initialPreset === 'local')
                                Access: Local Invoice Submissions, Physical Document Verification, and Payment Processing.
                            @else
                                Access: Material Requisitions (PR), Quotations, Purchase Orders (PO), and QC Inspections.
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
                Payment Term (Days) <span class="text-danger">*</span>
            </label>
            <input
                type="number"
                name="payment_term_days"
                id="payment-term-days"
                class="form-control @error('payment_term_days') is-invalid @enderror"
                min="1"
                max="365"
                placeholder="e.g. 30"
                value="{{ old('payment_term_days', isset($user) ? $user->supplier?->payment_term_days : '') }}"
                {{ $isPaymentTermRequired ? 'required' : '' }}
            >
            <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1 tw-block">
                Default payment terms (1–365 days) required for local invoice processing.
            </small>
            @error('payment_term_days')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</x-ui.form-section>
