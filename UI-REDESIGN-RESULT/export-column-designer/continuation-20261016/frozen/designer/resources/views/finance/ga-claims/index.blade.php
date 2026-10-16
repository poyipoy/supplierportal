@extends('layouts.app')
@section('title', __('finance.ga.register_title').' - '.__('finance.labels.finance_ap'))
@section('page-title', __('finance.ga.register_title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.ga.register_title')"
        :description="__('finance.ga.register_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>{{ __('finance.ga.manage_drp') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="tw-p-4 tw-rounded-lg tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-sm tw-flex tw-items-center tw-gap-3">
            <x-ui.icon name="check-circle" size="md" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    {{-- Filter Card --}}
    <x-ui.card>
        <form method="GET" action="{{ route('finance.ga-claims.index') }}" class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center tw-gap-3">
            <div class="tw-flex-1">
                <input
                    type="text"
                    name="search"
                    class="form-control form-control-sm"
                    placeholder="{{ __('ga.filters.claim_search') }}"
                    value="{{ request('search') }}"
                >
            </div>
            <div class="tw-w-44">
                <select name="status" class="form-select form-select-sm">
                    <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                    <option value="SUBMITTED" @selected(request('status') === 'SUBMITTED')>{{ __('local_invoice.labels.submitted') }}</option>
                    <option value="BASIC_VERIFIED" @selected(request('status') === 'BASIC_VERIFIED')>{{ \App\Support\StatusHelper::gaClaimLabel('BASIC_VERIFIED') }}</option>
                    <option value="NEED_REVISION" @selected(request('status') === 'NEED_REVISION')>{{ \App\Support\StatusHelper::gaClaimLabel('NEED_REVISION') }}</option>
                    <option value="READY_TO_PAY" @selected(request('status') === 'READY_TO_PAY')>{{ \App\Support\StatusHelper::gaClaimLabel('READY_TO_PAY') }}</option>
                    <option value="PAID" @selected(request('status') === 'PAID')>{{ __('local_invoice.labels.paid') }}</option>
                </select>
            </div>
            <div class="tw-w-48">
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">{{ __('ga.filters.all_employees') }}</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>
                            {{ $emp->name }} ({{ $emp->department }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="submit" variant="primary" size="sm">
                    <x-ui.icon name="search" size="sm" />
                    <span>{{ __('common.labels_review.filter') }}</span>
                </x-ui.button>
                @if(request()->hasAny(['search', 'status', 'employee_id']))
                    <x-ui.button :href="route('finance.ga-claims.index')" variant="ghost" size="sm">
                        <span>{{ __('common.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Data Table --}}
    <x-ui.data-table
        :title="__('ga.list.heading')"
        :description="__('finance.ga.verification_help')"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('finance.ga.claim_number') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.date') }}</th>
                        <th scope="col">{{ __('ga.labels.employee_department') }}</th>
                        <th scope="col">{{ __('ga.labels.claim_type') }}</th>
                        <th scope="col">{{ __('finance.ga.amount_rupiah') }}</th>
                        <th scope="col">{{ __('finance.drp.transfer_destination') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="text-end">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($claims as $claim)
                        <tr>
                            <td><strong class="tw-font-mono tw-text-primary">{{ $claim->claim_number }}</strong></td>
                            <td>{{ $regionalFormatter->date($claim->claim_date, 'dmy') }}</td>
                            <td>
                                <strong class="tw-text-on-surface tw-block">{{ $claim->employee?->name }}</strong>
                                <span class="tw-text-on-surface-variant tw-text-[11px]">{{ $claim->employee?->department }}</span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                    {{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}
                                </span>
                            </td>
                            <td><strong class="tw-font-mono tw-text-ui-sm">Rp {{ $regionalFormatter->number(number_format($claim->amount, 0, ',', '.'), 'indonesian') }}</strong></td>
                            <td>
                                <span class="tw-font-semibold">{{ $claim->employee?->bank_name }}</span> ·
                                <span class="tw-font-mono">{{ $claim->employee?->account_number }}</span>
                                <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">{{ __('finance.drp_surface.account_holder') }} {{ $claim->employee?->account_holder_name }}</span>
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::gaClaimTone($claim->status)">
                                    {{ \App\Support\StatusHelper::gaClaimLabel($claim->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.ga-claims.show', $claim)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('finance.labels.verification_short') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant">{{ __('finance.copy_review.ga_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($claims->hasPages())
            <x-slot:pagination>
                {{ $claims->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
