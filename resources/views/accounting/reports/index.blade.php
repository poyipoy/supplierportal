@extends('layouts.app')
@section('title', 'Local Invoice Reports')
@section('page-title', 'Local Invoice Reports')
@section('content')
<x-ui.page-header title="Local Invoice Reports" description="Export the invoice register or payment schedule using the selected filters." />
<x-ui.card>
@include('local-invoices.filters')
<form method="POST" action="{{ route('accounting.reports.export') }}" class="local-invoice-form">@csrf
@foreach(request()->only(['q','supplier','status','from','to','due_from','due_to','overdue','history']) as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<label for="report" class="form-label">Report</label><select class="form-select mb-3" id="report" name="report"><option value="register">Invoice Register</option><option value="payments">Payment Schedule</option></select>
<x-ui.button type="submit">Export Excel</x-ui.button>
</form>
<p class="mt-3">Generated files are available in <a href="{{ route('exports.index') }}">Export History</a>.</p>
</x-ui.card>
@include('local-invoices.scripts')
@endsection
