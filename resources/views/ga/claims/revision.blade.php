@extends('layouts.app')
@section('title', __('ga.review.revision_title', ['number' => $claim->claim_number]))
@section('page-title', __('ga.labels.claim_revision'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('ga.review.revision_heading', ['number' => $claim->claim_number])"
        :description="__('ga.review.revision_help', ['name' => $claim->employee?->name, 'department' => $claim->employee?->department, 'revision' => $claim->revision_number + 1])"
        :eyebrow="__('ga.dashboard.operational')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.show', $claim)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_detail') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Revision Reason Alert --}}
    @if($claim->revision_reason)
        <div class="tw-p-4 tw-rounded-lg tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-sm tw-space-y-1">
            <div class="tw-font-semibold tw-flex tw-items-center tw-gap-2">
                <x-ui.icon name="alert-circle" size="sm" />
                <span>{{ __('ga.review.revision_notes') }}</span>
            </div>
            <p class="tw-m-0 tw-text-ui-xs tw-font-medium">{{ $claim->revision_reason }}</p>
        </div>
    @endif

    <div class="tw-max-w-3xl tw-mx-auto tw-w-full">
        <x-ui.card :title="__('ga.labels.employee_revision')">
            <form method="POST" action="{{ route('ga.claims.resubmit', $claim) }}" enctype="multipart/form-data">
                @csrf
                <div class="tw-space-y-4">
                    {{-- Karyawan (Read-Only) --}}
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.employee_payee') }}</label>
                        <div class="tw-p-2.5 tw-rounded tw-bg-surface-container tw-text-ui-xs">
                            <strong>{{ $claim->employee?->name }}</strong> ({{ $claim->employee?->department }}) — {{ __('finance.drp_ui.bank_details', ['bank' => $claim->employee?->bank_name, 'account' => $claim->employee?->account_number, 'holder' => $claim->employee?->account_holder_name]) }}
                        </div>
                    </div>

                    {{-- Tipe Klaim & Tanggal --}}
                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                        <div>
                            <label for="claim_type" class="form-label tw-text-ui-xs tw-font-semibold">
                                {{ __('ga.labels.claim_type') }} <span class="text-danger">*</span>
                            </label>
                            <select name="claim_type" id="claim_type" class="form-select form-select-sm" required>
                                @foreach($claimTypes as $type)
                                    <option value="{{ $type }}" @selected(old('claim_type', $claim->claim_type) === $type)>
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
                                :value="old('claim_date', $claim->claim_date?->format('Y-m-d'))"
                                required
                            />
                        </div>
                    </div>

                    {{-- Nominal --}}
                    <div>
                        <label for="amount" class="form-label tw-text-ui-xs tw-font-semibold">
                            {{ __('ga.form.amount') }} <span class="text-danger">*</span>
                        </label>
                        <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-sm" value="{{ old('amount', $claim->amount) }}" required min="1">
                    </div>

                    {{-- Deskripsi --}}
                    <div>
                        <label for="description" class="form-label tw-text-ui-xs tw-font-semibold">
                            {{ __('ga.form.remarks') }}
                        </label>
                        <textarea name="description" id="description" class="form-control form-control-sm" rows="3">{{ old('description', $claim->description) }}</textarea>
                    </div>

                    {{-- Dokumen Pendukung Baru (Opsional) --}}
                    <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                        <label for="supporting" class="form-label tw-text-ui-xs tw-font-semibold">
                            {{ __('ga.form.upload_revision') }}
                        </label>
                        <input type="file" name="supporting" id="supporting" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.doc,.docx" aria-describedby="supporting-help" @if($errors->has('supporting')) aria-invalid="true" @endif>
                        <span id="supporting-help" class="tw-text-[11px] tw-text-on-surface-variant tw-block tw-mt-1">
                            {{ __('ga.form.entertainment_supporting') }}
                            {{ __('ga.form.revision_formats') }}
                        </span>
                    </div>

                    @error('supporting')
                        <p class="text-danger" role="alert">{{ $message }}</p>
                    @enderror

                    {{-- Submit Button --}}
                    <div class="tw-flex tw-justify-end tw-gap-2 tw-pt-4">
                        <x-ui.button :href="route('ga.claims.show', $claim)" variant="ghost" size="sm">
                            <span>{{ __('local_invoice.actions.cancel') }}</span>
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="send" size="sm" />
                            <span>{{ __('ga.closure.submit_revision', ['number' => $claim->revision_number + 1]) }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
