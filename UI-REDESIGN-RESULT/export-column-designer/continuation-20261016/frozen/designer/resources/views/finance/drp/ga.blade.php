@extends('layouts.app')
@section('title', __('finance.closure.ga_title', ['audience' => 'Finance AP']))
@section('page-title', __('finance.drp.ga_heading'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.drp.general_affairs')"
        :description="__('finance.drp_surface.ga_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>{{ __('finance.drp.open_supplier') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="info" :title="__('finance.drp_surface.ga_workflow')">
        {{ __('common.final_copy.ga_workflow', ['amount' => 'Rp 0']) }}
    </x-ui.alert>

    <x-ui.card :title="__('finance.candidates.create_ga')" :description="__('finance.candidates.ga_help')">
        <form method="GET" action="{{ route('finance.drp.ga') }}" class="tw-flex tw-flex-wrap tw-items-end tw-gap-3 tw-mb-4">
            <div><label for="ga-candidate-search" class="form-label">{{ __('finance.candidates.search_ga') }}</label><input id="ga-candidate-search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" maxlength="100"></div>
            <div><label for="ga-candidate-type" class="form-label">{{ __('ga.labels.claim_type') }}</label><select id="ga-candidate-type" name="claim_type" class="form-select form-select-sm"><option value="">{{ __('finance.candidates.all_types') }}</option>@foreach(\App\Models\GaClaim::CLAIM_TYPES as $type)<option value="{{ $type }}" @selected(request('claim_type') === $type)>{{ \App\Models\GaClaim::claimTypeLabel($type) }}</option>@endforeach</select></div>
            <x-ui.button type="submit" variant="outline" size="sm">{{ __('common.labels_review.filter') }}</x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="ghost" size="sm">{{ __('finance.candidates.reset') }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('finance.drp.ga.create') }}">
            @csrf
            <div class="table-responsive">
                <table class="table table-hover align-middle tw-text-ui-sm w-100">
                    <thead><tr>
                        <th scope="col">{{ __('common.actions.choose') }}</th>
                        <th scope="col">{{ __('finance.drp.payee_account') }}</th>
                        <th scope="col">{{ __('ga.review.claim_number') }}</th>
                        <th scope="col">{{ __('ga.labels.claim_type') }}</th>
                        <th scope="col">{{ __('finance.candidates.verification_date') }}</th>
                        <th scope="col" class="text-end">{{ __('finance.drp.total_amount_rp') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse($eligibleClaims as $claim)
                            <tr>
                                <td><input type="checkbox" class="form-check-input" name="claim_ids[]" value="{{ $claim->id }}" aria-label="{{ __('finance.candidates.select_claim', ['number' => $claim->claim_number]) }}"></td>
                                <td><strong>{{ $claim->employee->name }}</strong><span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ __('finance.drp_ui.bank_details', ['bank' => $claim->employee->bank_name, 'account' => $claim->employee->account_number, 'holder' => $claim->employee->account_holder_name]) }}</span></td>
                                <td>{{ $claim->claim_number }}</td>
                                <td>{{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}</td>
                                <td>@bizdt($claim->ready_to_pay_at)</td>
                                <td class="text-end tw-font-mono">Rp {{ $regionalFormatter->number(number_format($claim->amount, 0, ',', '.'), 'indonesian') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center tw-py-8">{{ __('finance.candidates.empty_ga') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $eligibleClaims->links() }}
            @if($eligibleClaims->isNotEmpty())
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-mt-3">
                    <input type="text" name="notes" class="form-control form-control-sm" maxlength="1000" aria-label="{{ __('common.final_review.batch_notes') }}" placeholder="{{ __('common.final_review.batch_notes') }}">
                    <x-ui.button type="submit" size="sm">{{ __('finance.candidates.create_ga') }}</x-ui.button>
                </div>
            @endif
        </form>
    </x-ui.card>

    <x-ui.data-table
        :title="__('finance.drp.ga_list')"
        :description="__('finance.drp.ga_submissions')"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_surface.batch_number') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.date') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.active_employees') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('finance.drp.total_amount_rp') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $batch->batch_number }}</strong>
                            </td>
                            <td>{{ $regionalFormatter->date($batch->created_at, 'human') }}</td>
                            <td>{{ trans_choice('common.final_copy.employee_count', $batch->groups_count) }}</td>
                            <td class="text-end tw-font-mono tw-font-semibold tw-text-primary">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::paymentBatchTone($batch->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($batch->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('finance.drp.review_pay') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                {{ __('finance.drp.empty_ga_submitted') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <x-slot:pagination>
                {{ $batches->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
