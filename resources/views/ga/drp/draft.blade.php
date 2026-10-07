@extends('layouts.app')
@section('title', __('ga.actions.prepare_drp_page'))
@section('page-title', __('ga.actions.prepare_drp'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.drp.prepare_ga')"
        :description="__('ga.review.drp_help')"
        :eyebrow="__('ga.dashboard.operational')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('ga.detail.back') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card
        :title="__('ga.review.ready_claims')"
        :description="__('finance.drp.ga_selection')"
    >
        <form method="POST" action="{{ route('ga.drp-draft.store') }}">
            @csrf
            <div class="tw-space-y-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 40px;">{{ __('common.actions.choose') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.review.claim_number') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.employee_payee') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.claim_type') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.bank_account') }}</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('ga.labels.claim_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($eligibleClaims as $claim)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" class="form-check-input">
                                    </td>
                                    <td>
                                        <strong class="tw-font-mono tw-text-on-surface">{{ $claim->claim_number }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $regionalFormatter->date($claim->claim_date, 'human') }}</span>
                                    </td>
                                    <td>
                                        <strong class="tw-text-on-surface">{{ $claim->employee?->name }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $claim->employee?->department }}</span>
                                    </td>
                                    <td>
                                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                            {{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ __('finance.drp_ui.bank_details', ['bank' => $claim->employee?->bank_name, 'account' => $claim->employee?->account_number, 'holder' => $claim->employee?->account_holder_name]) }}
                                    </td>
                                    <td class="text-end tw-font-mono tw-font-bold tw-text-primary">
                                        Rp {{ number_format($claim->amount, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                        {{ __('finance.drp.empty_ga_candidates') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($eligibleClaims->isNotEmpty())
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3">
                        <div class="tw-flex-1">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="{{ __('ga.review.batch_notes') }}">
                        </div>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="check" size="sm" />
                            <span>{{ __('ga.review.send_drp') }}</span>
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
