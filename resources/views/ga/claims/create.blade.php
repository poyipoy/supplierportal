@extends('layouts.app')
@section('title', __('ga.form.page_title'))
@section('page-title', __('ga.labels.claim_form'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('ga.form.heading')"
        :description="__('ga.form.description')"
        :eyebrow="__('ga.dashboard.operational')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('ga.detail.back') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-max-w-3xl tw-mx-auto tw-w-full">
        <x-ui.card :title="__('ga.labels.employee_form')">
            <form method="POST" action="{{ route('ga.claims.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="tw-space-y-4">
                    {{-- Karyawan Dropdown (Searchable Select) --}}
                    @php
                        $resolvedEmployeeOptions = $employeeOptions ?? ($employees ?? collect())->map(function ($emp) {
                            $accountDetail = __('finance.drp_ui.bank_details', [
                                'bank' => $emp->bank_name,
                                'account' => $emp->account_number,
                                'holder' => $emp->account_holder_name,
                            ]);

                            return [
                                'value' => (string) $emp->id,
                                'label' => $emp->name,
                                'sublabel' => $emp->department . ' · ' . $accountDetail,
                                'badge' => $emp->bank_name,
                                'badgeTone' => method_exists($emp, 'isBca') && $emp->isBca() ? 'neutral' : 'warning',
                                'searchKeywords' => strtolower(implode(' ', array_filter([
                                    $emp->name,
                                    $emp->department,
                                    $emp->bank_name,
                                    $emp->account_number,
                                    $emp->account_holder_name,
                                ]))),
                            ];
                        })->values()->all();
                    @endphp

                    <div>
                        <x-ui.searchable-select
                            name="employee_id"
                            id="employee_id"
                            :label="__('ga.labels.employee_receiver')"
                            :placeholder="__('ga.form.employee_option')"
                            :search-placeholder="__('ga.form.search_employee')"
                            :options="$resolvedEmployeeOptions"
                            :value="old('employee_id')"
                            required
                            :helper="__('ga.form.bank_security')"
                            :empty-message="__('ga.form.employee_empty')"
                        />
                    </div>


                    {{-- Tipe Klaim & Tanggal --}}
                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                        <div>
                            <label for="claim_type" class="form-label tw-text-ui-xs tw-font-semibold">
                                {{ __('ga.labels.claim_type') }} <span class="text-danger">*</span>
                            </label>
                            <select name="claim_type" id="claim_type" class="form-select form-select-sm" required>
                                <option value="">{{ __('ga.form.type_option') }}</option>
                                @foreach($claimTypes as $type)
                                    <option value="{{ $type }}" @selected(old('claim_type') === $type)>
                                        {{ \App\Models\GaClaim::claimTypeLabel($type) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="claim_date" class="form-label tw-text-ui-xs tw-font-semibold">
                                {{ __('ga.form.event_date') }} <span class="text-danger">*</span>
                            </label>
                            <x-ui.date-picker
                                name="claim_date"
                                id="claim_date"
                                :value="old('claim_date', now()->format('Y-m-d'))"
                                required
                            />
                        </div>
                    </div>

                    {{-- Nominal --}}
                    <div>
                        <label for="amount" class="form-label tw-text-ui-xs tw-font-semibold">
                            {{ __('ga.form.amount') }} <span class="text-danger">*</span>
                        </label>
                        <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-sm" placeholder="{{ __('ga.form.example_amount') }}" value="{{ old('amount') }}" required min="1">
                    </div>

                    {{-- Deskripsi --}}
                    <div>
                        <label for="description" class="form-label tw-text-ui-xs tw-font-semibold">
                            {{ __('ga.form.remarks') }}
                        </label>
                        <textarea name="description" id="description" class="form-control form-control-sm" rows="3" placeholder="{{ __('ga.form.description_placeholder') }}">{{ old('description') }}</textarea>
                    </div>

                    {{-- Dokumen Pendukung --}}
                    <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                        <label for="supporting" class="form-label tw-text-ui-xs tw-font-semibold">
                            {{ __('ga.form.upload_supporting') }} <span class="tw-text-on-surface-variant font-normal">{{ __('ga.form.optional') }}</span>
                        </label>
                        <input type="file" name="supporting" id="supporting" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.doc,.docx">
                        <span class="tw-text-[11px] tw-text-on-surface-variant tw-block tw-mt-1">
                            {{ __('ga.form.support_formats') }}
                        </span>
                    </div>

                    {{-- Submit Button --}}
                    <div class="tw-flex tw-justify-end tw-gap-2 tw-pt-4">
                        <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                            <span>{{ __('local_invoice.actions.cancel') }}</span>
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="check" size="sm" />
                            <span>{{ __('ga.form.submit_receipt') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
