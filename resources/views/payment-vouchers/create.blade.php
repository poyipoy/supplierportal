@extends('layouts.app')
@section('title', __('payment_voucher.create_page_title'))
@section('page-title', __('payment_voucher.actions.new'))

@section('content')
@php
    use App\Models\PaymentVoucher;

    $initialPayeeType = old('payee_type', $duplicatePrefill['payee_type'] ?? PaymentVoucher::PAYEE_SUPPLIER);
    $initialSupplierHash = old('payee_supplier_hash', $duplicatePrefill['payee_supplier_hash'] ?? '');
    $initialEmployeeId = old('payee_employee_id', $duplicatePrefill['payee_employee_id'] ?? '');
    $initialMethod = old('payment_method', $duplicatePrefill['payment_method'] ?? PaymentVoucher::METHOD_BANK);
    $initialDate = old('voucher_date', $defaultDate);
    $initialDpp = old('dpp_amount', $duplicatePrefill['dpp_amount'] ?? '');
    $initialPpn = old('ppn_amount', $duplicatePrefill['ppn_amount'] ?? '0');
    $initialPph = old('pph_amount', $duplicatePrefill['pph_amount'] ?? '0');
    $initialBankFee = old('bank_fee', $duplicatePrefill['bank_fee'] ?? '0');
    $forceBreakdown = $errors->hasAny(['ppn_amount', 'pph_amount', 'bank_fee']);
@endphp

<div class="tw:mx-auto tw:max-w-4xl tw:pb-20">
    <x-ui.page-header
        :title="__('payment_voucher.actions.new')"
        :description="__('payment_voucher.list.description')"
        :eyebrow="__('payment_voucher.eyebrow')"
        class="tw:mb-6"
    >
        <x-slot:actions>
            <x-ui.button :href="route($routePrefix.'.index')" size="sm" variant="outline">
                <x-slot:leading><x-ui.icon name="arrow-left" size="sm" /></x-slot:leading>
                {{ __('payment_voucher.actions.back_list') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form
        method="POST"
        action="{{ route($routePrefix.'.store') }}"
        enctype="multipart/form-data"
        data-async-submit
        class="tw:grid tw:gap-6"
        x-data="paymentVoucherForm({
            payeeType: @js($initialPayeeType),
            supplierHash: @js($initialSupplierHash),
            employeeId: @js((string) $initialEmployeeId),
            paymentMethod: @js($initialMethod),
            dpp: @js((string) $initialDpp),
            ppn: @js((string) $initialPpn),
            pph: @js((string) $initialPph),
            bankFee: @js((string) $initialBankFee),
            forceBreakdown: @js($forceBreakdown),
            supplierMap: @js($supplierMap),
            employeeMap: @js($employeeMap),
        })"
        @change="handleFieldChange($event)"
        @adasi:reveal-field="if (['ppn_amount', 'pph_amount', 'bank_fee'].includes($event.detail?.key)) { showBreakdown = true }"
    >
        @csrf

        {{-- Section 1: Payee & Bank Snapshot --}}
        <x-ui.form-section
            :title="__('payment_voucher.sections.payee')"
            :description="__('payment_voucher.sections.payee_help')"
        >
            <div class="tw:grid tw:gap-4">
                <fieldset class="tw:m-0 tw:border-0 tw:p-0">
                    <legend class="tw:mb-2 tw:text-ui-sm tw:font-medium tw:text-on-surface">
                        {{ __('payment_voucher.fields.payee_type') }}
                        <span class="tw:text-error" aria-hidden="true">*</span>
                    </legend>
                    <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-3 tw:gap-2.5">
                        @foreach([
                            PaymentVoucher::PAYEE_SUPPLIER => __('payment_voucher.payee_types.supplier'),
                            PaymentVoucher::PAYEE_EMPLOYEE => __('payment_voucher.payee_types.employee'),
                            PaymentVoucher::PAYEE_MANUAL => __('payment_voucher.payee_types.manual'),
                        ] as $typeValue => $typeLabel)
                            <label
                                class="ui-motion tw:flex tw:min-h-11 tw:cursor-pointer tw:items-center tw:gap-2.5 tw:rounded-ui-sm tw:border tw:px-3.5 tw:py-2.5 tw:text-ui-sm tw:font-medium"
                                :class="payeeType === @js($typeValue) ? 'tw:border-primary tw:bg-primary-container tw:text-primary-container-foreground' : 'tw:border-outline-strong tw:bg-surface tw:text-on-surface'"
                            >
                                <input
                                    type="radio"
                                    name="payee_type"
                                    value="{{ $typeValue }}"
                                    x-model="payeeType"
                                    class="ui-focus-ring tw:h-4 tw:w-4"
                                >
                                <span>{{ $typeLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('payee_type')
                        <p class="tw:m-0 tw:mt-1.5 tw:text-ui-xs tw:font-medium tw:text-error" role="alert">{{ $message }}</p>
                    @enderror
                </fieldset>

                {{-- Registered Supplier Select + Authoritative Bank Preview --}}
                <div x-show="payeeType === 'SUPPLIER'" x-cloak class="tw:grid tw:gap-3">
                    <x-ui.searchable-select
                        name="payee_supplier_hash"
                        :label="__('payment_voucher.fields.supplier')"
                        :placeholder="__('payment_voucher.fields.supplier_placeholder')"
                        :emptyMessage="__('payment_voucher.fields.supplier_empty')"
                        :helper="__('payment_voucher.fields.supplier_helper')"
                        :value="$initialSupplierHash"
                        :options="$supplierOptions"
                        showSublabel="true"
                    />

                    <div x-show="selectedSupplier !== null" x-cloak aria-live="polite">
                        <template x-if="selectedSupplier">
                            <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3.5 tw:grid tw:gap-1.5 tw:text-ui-xs">
                                <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                                    <span class="tw:font-semibold tw:text-on-surface" x-text="selectedSupplier.name"></span>
                                    <span class="tw:text-on-surface-variant">
                                        NPWP: <span class="ui-tabular-nums tw:font-medium tw:text-on-surface" x-text="selectedSupplier.npwp"></span>
                                    </span>
                                </div>
                                <template x-if="selectedSupplier.has_verified_bank">
                                    <div class="tw:text-on-surface-variant">
                                        <span class="tw:font-medium tw:text-on-surface" x-text="selectedSupplier.bank_name"></span>
                                        · <span class="ui-tabular-nums" x-text="selectedSupplier.account_number"></span>
                                        (<span x-text="selectedSupplier.account_holder_name"></span>)
                                    </div>
                                </template>
                                <template x-if="!selectedSupplier.has_verified_bank">
                                    <div class="tw:text-error tw:font-medium" x-show="needsBankFields">
                                        {{ __('payment_voucher.labels.bank_preview_missing') }}
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Active Employee Select + Authoritative Bank Preview --}}
                <div x-show="payeeType === 'EMPLOYEE'" x-cloak class="tw:grid tw:gap-3">
                    <x-ui.searchable-select
                        name="payee_employee_id"
                        :label="__('payment_voucher.fields.employee')"
                        :placeholder="__('payment_voucher.fields.employee_placeholder')"
                        :emptyMessage="__('payment_voucher.fields.employee_empty')"
                        :helper="__('payment_voucher.fields.employee_helper')"
                        :value="(string) $initialEmployeeId"
                        :options="$employeeOptions"
                        showSublabel="true"
                    />

                    <div x-show="selectedEmployee !== null" x-cloak aria-live="polite">
                        <template x-if="selectedEmployee">
                            <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3.5 tw:grid tw:gap-1 tw:text-ui-xs">
                                <div class="tw:font-semibold tw:text-on-surface">
                                    <span x-text="selectedEmployee.name"></span> · <span x-text="selectedEmployee.department"></span>
                                </div>
                                <div class="tw:text-on-surface-variant">
                                    <span class="tw:font-medium tw:text-on-surface" x-text="selectedEmployee.bank_name"></span>
                                    · <span class="ui-tabular-nums" x-text="selectedEmployee.account_number"></span>
                                    (<span x-text="selectedEmployee.account_holder_name"></span>)
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Manual Payee Inputs --}}
                <div x-show="payeeType === 'MANUAL'" x-cloak class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
                    <x-ui.input
                        name="payee_name"
                        :label="__('payment_voucher.fields.payee_name')"
                        :placeholder="__('payment_voucher.fields.payee_name_placeholder')"
                        :value="$duplicatePrefill['payee_name'] ?? null"
                        maxlength="160"
                    />
                    <x-ui.input
                        name="npwp"
                        :label="__('payment_voucher.fields.npwp')"
                        :placeholder="__('payment_voucher.fields.npwp_placeholder')"
                        :value="$duplicatePrefill['npwp'] ?? null"
                        maxlength="32"
                    />

                    <div class="tw:md:col-span-2" x-show="needsBankFields" x-cloak>
                        <div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-3 tw:gap-4">
                            <x-ui.searchable-select
                                name="bank_name"
                                :label="__('payment_voucher.fields.bank_name')"
                                :placeholder="__('payment_voucher.fields.bank_placeholder')"
                                :searchPlaceholder="__('payment_voucher.fields.bank_search')"
                                :value="$duplicatePrefill['bank_name'] ?? null"
                                :options="$bankOptions"
                                allowCustom="true"
                            />
                            <x-ui.input
                                name="account_number"
                                :label="__('payment_voucher.fields.account_number')"
                                :value="$duplicatePrefill['account_number'] ?? null"
                                maxlength="60"
                                class="ui-tabular-nums"
                            />
                            <x-ui.input
                                name="account_holder_name"
                                :label="__('payment_voucher.fields.account_holder')"
                                :value="$duplicatePrefill['account_holder_name'] ?? null"
                                maxlength="120"
                            />
                        </div>
                    </div>
                </div>

                {{-- Payment Method Radio & Date --}}
                <div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4 tw:border-t tw:border-outline-variant tw:pt-4">
                    <fieldset class="tw:m-0 tw:border-0 tw:p-0">
                        <legend class="tw:mb-2 tw:text-ui-sm tw:font-medium tw:text-on-surface">
                            {{ __('payment_voucher.fields.payment_method') }}
                            <span class="tw:text-error" aria-hidden="true">*</span>
                        </legend>
                        <div class="tw:grid tw:grid-cols-2 tw:gap-2.5">
                            @foreach([
                                PaymentVoucher::METHOD_BANK => __('payment_voucher.methods.bank'),
                                PaymentVoucher::METHOD_KAS => __('payment_voucher.methods.kas'),
                            ] as $methodValue => $methodLabel)
                                <label
                                    class="ui-motion tw:flex tw:min-h-11 tw:cursor-pointer tw:items-center tw:gap-2 tw:rounded-ui-sm tw:border tw:px-3 tw:py-2 tw:text-ui-sm tw:font-medium"
                                    :class="paymentMethod === @js($methodValue) ? 'tw:border-primary tw:bg-primary-container tw:text-primary-container-foreground' : 'tw:border-outline-strong tw:bg-surface tw:text-on-surface'"
                                >
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="{{ $methodValue }}"
                                        x-model="paymentMethod"
                                        class="ui-focus-ring tw:h-4 tw:w-4"
                                    >
                                    <span>{{ $methodLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p x-show="!needsBankFields" x-cloak class="tw:m-0 tw:mt-1.5 tw:text-ui-xs tw:text-on-surface-variant">
                            {{ __('payment_voucher.labels.kas_no_bank_notice') }}
                        </p>
                        @error('payment_method')
                            <p class="tw:m-0 tw:mt-1.5 tw:text-ui-xs tw:font-medium tw:text-error" role="alert">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <x-ui.date-picker
                        name="voucher_date"
                        :label="__('payment_voucher.fields.voucher_date')"
                        :value="$initialDate"
                        required
                    />
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 2: Voucher Information & Purpose --}}
        <x-ui.form-section
            :title="__('payment_voucher.sections.details')"
            :description="__('payment_voucher.sections.details_help')"
        >
            <div class="tw:grid tw:gap-4">
                <x-ui.input
                    name="reference_number"
                    :label="__('payment_voucher.fields.reference_number')"
                    :placeholder="__('payment_voucher.fields.reference_placeholder')"
                    :value="$duplicatePrefill['reference_number'] ?? null"
                    maxlength="120"
                />

                <x-ui.textarea
                    name="description"
                    :label="__('payment_voucher.fields.description')"
                    :placeholder="__('payment_voucher.fields.description_placeholder')"
                    :value="$duplicatePrefill['description'] ?? null"
                    rows="3"
                    maxlength="1000"
                    required
                />

                <x-ui.textarea
                    name="notes"
                    :label="__('payment_voucher.fields.notes')"
                    :placeholder="__('payment_voucher.fields.notes_placeholder')"
                    :value="$duplicatePrefill['notes'] ?? null"
                    rows="2"
                    maxlength="1000"
                />
            </div>
        </x-ui.form-section>

        {{-- Section 3: Payment Amount & Breakdown --}}
        <x-ui.form-section
            :title="__('payment_voucher.sections.amounts')"
            :description="__('payment_voucher.sections.amounts_help')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    @click="showBreakdown = !showBreakdown"
                    :aria-expanded="showBreakdown"
                    aria-controls="pv-tax-breakdown-fields"
                    class="ui-focus-ring tw:min-h-9 tw:rounded-ui-sm tw:border tw:border-outline-strong tw:bg-surface tw:px-3 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:hover:bg-surface-container"
                >
                    <span x-text="showBreakdown ? @js(__('payment_voucher.actions.toggle_breakdown_hide')) : @js(__('payment_voucher.actions.toggle_breakdown_show'))"></span>
                </button>
            </x-slot:actions>

            <div class="tw:grid tw:gap-4">
                <div class="tw:grid tw:grid-cols-1 tw:md:grid-cols-2 tw:gap-4">
                    <x-ui.input
                        name="dpp_amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        :label="__('payment_voucher.fields.dpp_amount')"
                        :value="$initialDpp"
                        x-model="dpp"
                        required
                        class="ui-tabular-nums"
                    />

                    <div class="tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3.5 tw:flex tw:flex-col tw:justify-center" aria-live="polite" aria-atomic="true">
                        <span class="tw:text-ui-xs tw:font-medium tw:text-on-surface-variant">
                            {{ __('payment_voucher.fields.net_amount') }}
                        </span>
                        <span class="ui-tabular-nums tw:mt-0.5 tw:text-lg tw:font-bold" :class="totals.isPositive ? 'tw:text-on-surface' : 'tw:text-error'">
                            Rp <span x-text="formattedNet">0,00</span>
                        </span>
                    </div>
                </div>

                <div id="pv-tax-breakdown-fields" x-show="showBreakdown" x-cloak class="tw:grid tw:grid-cols-1 tw:md:grid-cols-3 tw:gap-4 tw:border-t tw:border-outline-variant tw:pt-4">
                    <x-ui.input
                        name="ppn_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="__('payment_voucher.fields.ppn_amount')"
                        :value="$initialPpn"
                        x-model="ppn"
                        class="ui-tabular-nums"
                    />
                    <x-ui.input
                        name="pph_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="__('payment_voucher.fields.pph_amount')"
                        :value="$initialPph"
                        x-model="pph"
                        class="ui-tabular-nums"
                    />
                    <x-ui.input
                        name="bank_fee"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="__('payment_voucher.fields.bank_fee')"
                        :value="$initialBankFee"
                        x-model="bankFee"
                        class="ui-tabular-nums"
                    />
                </div>

                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                    {{ __('payment_voucher.labels.server_authority_note') }}
                </p>
            </div>
        </x-ui.form-section>

        {{-- Section 4: Optional Supporting Document --}}
        <x-ui.form-section
            :title="__('payment_voucher.sections.supporting')"
            :description="__('payment_voucher.sections.supporting_help')"
        >
            <x-ui.file-upload
                name="supporting"
                :label="__('payment_voucher.fields.supporting_file')"
                :helper="__('payment_voucher.fields.supporting_formats')"
                accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.doc,.docx"
                :allowedExtensions="['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'doc', 'docx']"
                :maxSizeMb="10"
            />
        </x-ui.form-section>

        <x-ui.action-bar sticky>
            <x-slot:left>
                <x-ui.button :href="route($routePrefix.'.index')" size="md" variant="outline">
                    {{ __('payment_voucher.actions.cancel') }}
                </x-ui.button>
            </x-slot:left>
            <x-slot:right>
                <x-ui.button type="submit" size="md" variant="primary">
                    <x-slot:leading><x-ui.icon name="check-circle" size="sm" /></x-slot:leading>
                    {{ __('payment_voucher.actions.submit') }}
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection

