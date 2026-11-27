@extends('layouts.app')
@section('title', __('ga.list.page_title'))
@section('page-title', __('ga.list.heading'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header
        :title="__('ga.list.full_heading')"
        :description="__('ga.list.description')"
        :eyebrow="__('ga.dashboard.operational')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.dashboard')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('ga.claims.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('ga.actions.new') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.data-table
        :title="__('ga.list.title')"
        :description="__('ga.list.summary', ['count' => $claims->total()])"
    >
        <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-sm tw:w-full">
            <thead>
                <tr>
                    <th scope="col">{{ __('ga.labels.claim_number_receipt') }}</th>
                    <th scope="col">{{ __('ga.labels.employee_payee') }}</th>
                    <th scope="col">{{ __('ga.labels.claim_type') }}</th>
                    <th scope="col">{{ __('local_invoice.labels.date') }}</th>
                    <th scope="col" class="tw:text-end">{{ __('ga.labels.claim_amount') }}</th>
                    <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                    <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($claims as $c)
                    <tr>
                        <td>
                            <strong class="ui-tabular-nums tw:text-on-surface">{{ $c->claim_number }}</strong>
                            @if($c->receipt)
                                <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">
                                    {{ __('ga.receipt.receipt_number_label') }} {{ $c->receipt->receipt_number }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <strong class="tw:text-on-surface">{{ $c->employee?->name }}</strong>
                            <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">
                                {{ $c->employee?->department }} — {{ $c->employee?->bank_name }} ({{ $c->employee?->account_number }})
                            </span>
                        </td>
                        <td>
                            <span class="tw:inline-flex tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-ui-xs tw:font-semibold tw:bg-primary/10 tw:text-primary">
                                {{ \App\Models\GaClaim::claimTypeLabel($c->claim_type) }}
                            </span>
                        </td>
                        <td>{{ $regionalFormatter->date($c->claim_date, 'human') }}</td>
                        <td class="tw:text-end ui-tabular-nums tw:font-bold tw:text-on-surface">
                            Rp {{ $regionalFormatter->number(number_format($c->amount, 0, ',', '.'), 'indonesian') }}
                        </td>
                        <td>
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::gaClaimTone($c->status)">
                                {{ \App\Support\StatusHelper::gaClaimLabel($c->status) }}
                            </x-ui.status-chip>
                        </td>
                        <td class="tw:text-end tw:whitespace-nowrap">
                            <div class="tw:inline-flex tw:items-center tw:justify-end tw:gap-1.5">
                                <x-ui.row-action
                                    :href="route('ga.claims.show', $c)"
                                    :context="$c->claim_number"
                                />
                                @if($c->receipt)
                                    <x-ui.icon-button :href="route('ga.claims.receipt', $c)" target="_blank" variant="outline" size="sm" icon="printer" :label="__('local_invoice.actions.print_receipt')" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="tw:text-center tw:py-8 tw:text-on-surface-variant tw:text-ui-sm">
                            {{ __('ga.list.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($claims->hasPages())
            <x-slot:pagination>
                {{ $claims->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
