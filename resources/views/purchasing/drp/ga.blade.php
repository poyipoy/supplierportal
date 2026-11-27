@extends('layouts.app')
@section('title', __('finance.closure.ga_title', ['audience' => 'Purchasing']))
@section('page-title', __('finance.drp.ga_heading'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header
        :title="__('finance.drp.general_affairs')"
        :description="__('finance.drp_surface.ga_monitor_help')"
        :eyebrow="__('finance.drp_surface.purchasing_audience')"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>{{ __('finance.drp.open_supplier') }}</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.paid.index')" variant="outline" size="sm">
                <x-ui.icon name="badge-check" size="sm" />
                <span>{{ __('finance.drp.open_paid') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="info" :title="__('finance.drp_surface.ga_monitor')">
        {{ __('finance.copy_review.ga_read_only') }}
    </x-ui.alert>

    <x-ui.data-table
        :title="__('finance.drp.ga_list')"
        :description="__('finance.drp.ga_recorded')"
    >
        <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-sm tw:w-full">
                <thead>
                    <tr>
                        <th scope="col">{{ __('finance.drp_surface.batch_number') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.date') }}</th>
                        <th scope="col">{{ __('ga.labels.active_employees') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('finance.drp.total_amount_rp') }}</th>
                        <th scope="col">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>
                                <strong class="ui-tabular-nums tw:text-on-surface">{{ $batch->batch_number }}</strong>
                            </td>
                            <td>{{ $regionalFormatter->date($batch->created_at, 'human') }}</td>
                            <td>{{ trans_choice('common.final_copy.employee_count', $batch->groups_count) }}</td>
                            <td class="tw:text-end ui-tabular-nums tw:font-semibold tw:text-primary">
                                Rp {{ $regionalFormatter->money($batch->total_subtotal, 0) }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::paymentBatchTone($batch->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($batch->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="route('purchasing.drp.show', $batch)"
                                    :context="$batch->batch_number"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="tw:text-center tw:py-8 tw:text-on-surface-variant tw:text-ui-sm">
                                {{ __('finance.drp.empty_ga') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

        @if($batches->hasPages())
            <x-slot:pagination>
                {{ $batches->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
