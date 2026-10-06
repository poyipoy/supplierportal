@extends('layouts.app')
@section('title', __('accounting.closure.reports_title'))
@section('page-title', __('accounting.closure.reports_title'))
@section('content')
<x-ui.page-header :title="__('accounting.review.reports_title')" :description="__('accounting.labels.export_help')" />
<x-ui.card>
@include('local-invoices.filters')
<form method="POST" action="{{ route('accounting.reports.export') }}" class="local-invoice-form">@csrf
@foreach(request()->only(['q','supplier','status','from','to','due_from','due_to','overdue','history']) as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<label for="report" class="form-label">{{ __('accounting.labels.reports') }}</label><select class="form-select mb-3" id="report" name="report"><option value="register">{{ __('local_invoice.list.title') }}</option><option value="payments">{{ __('local_invoice.labels.payment_date') }}</option></select>
<x-ui.button type="submit">{{ __('exports.actions.excel') }}</x-ui.button>
</form>
<p class="mt-3"><a href="{{ route('exports.index') }}">{{ __('accounting.review.generated_files') }}</a></p>
</x-ui.card>
@include('local-invoices.scripts')
@endsection
