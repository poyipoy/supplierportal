@extends('layouts.app')
@section('title', __('accounting.closure.reports_title'))
@section('page-title', __('accounting.closure.reports_title'))
@section('content')
<x-ui.page-header :title="__('accounting.review.reports_title')" :description="__('accounting.labels.export_help')" />
<x-ui.card>
@include('local-invoices.filters')
<x-export.advanced-modal export-key="accounting.local-invoices" :action="route('accounting.reports.export')" :suppliers="$suppliers" :initial-filters="['report' => request('report', 'register')]" :filter-selectors="['q' => '#invoice-search', 'status' => '#invoice-status', 'supplier' => '#invoice-supplier', 'overpayment_status' => '#invoice-overpayment-status', 'overdue' => '#overdue', 'history' => '#history', 'from' => '#from', 'to' => '#to', 'due_from' => '#due_from', 'due_to' => '#due_to']" />
<p class="mt-3"><a href="{{ route('exports.index') }}">{{ __('accounting.review.generated_files') }}</a></p>
</x-ui.card>
@include('local-invoices.scripts')
@endsection
