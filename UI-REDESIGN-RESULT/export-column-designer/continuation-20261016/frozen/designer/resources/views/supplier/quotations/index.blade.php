@extends('layouts.app')

@section('title', __('supplier.copy.quotation_period_list_adasi_portal'))
@section('page-title', __('supplier.copy.quotation_periods'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.quotation_periods') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.copy.quotation_periods')"
        :eyebrow="__('supplier.copy.supplier_portal')"
        :description="__('supplier.copy.select_an_active_procurement_period_to_review_open_requisitions_and_submit_your_quotation_pricing')"
    >
        <x-slot:actions>
<x-export.advanced-modal export-key="supplier.quotations" :action="route('supplier.export.quotations')" :periods="$periods" />
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Data Table --}}
    <x-ui.data-table
        :title="__('supplier.copy.procurement_periods')"
        :description="__('supplier.copy.counts_indicate_open_requisitions_and_your_supplier_quotation_submissions_per_period')"
        :empty="$periods->isEmpty()"
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="ps-3">{{ __('supplier.copy.procurement_period') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.status') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.awaiting_quotation') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.submitted_quotations') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.rejected_quotations') }}</th>
                    <th scope="col" class="tw-w-44 text-end pe-3">{{ __('supplier.copy.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($periods as $period)
                    <tr>
                        <td class="fw-bold tw-text-on-surface ps-3">{{ $period->display_label }}</td>
                        <td class="text-center">
                            @if($period->status === 'open')
                                <span class="ui-status-chip ui-status-chip--success">
                                    <x-ui.icon name="circle-dot" size="sm" class="me-1" />{{ __('status.finance.open') }}
                                </span>
                            @else
                                <span class="ui-status-chip ui-status-chip--neutral">
                                    {{ __('status.claim.closed') }}
                                </span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($period->unresponded_prs > 0)
                                <span class="ui-status-chip ui-status-chip--error ui-tabular-nums">
                                    {{ trans_choice('purchasing.copy.pr_count', $period->unresponded_prs, ['count' => $period->unresponded_prs]) }}
                                </span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($period->responded_prs > 0)
                                <span class="ui-status-chip ui-status-chip--info ui-tabular-nums">
                                    {{ trans_choice('purchasing.copy.pr_count', $period->responded_prs, ['count' => $period->responded_prs]) }}
                                </span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($period->rejected_prs > 0)
                                <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">
                                    {{ trans_choice('purchasing.copy.pr_count', $period->rejected_prs, ['count' => $period->rejected_prs]) }}
                                </span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            <x-ui.button :href="route('supplier.quotations.period', $period->id)" size="sm" variant="outline">
                                <span>{{ __('supplier.copy.view_requisitions') }}</span>
                                <x-ui.icon name="arrow-right" size="sm" />
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection
