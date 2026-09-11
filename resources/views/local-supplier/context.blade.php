@extends('layouts.app')
@section('title', 'Choose Supplier Portal')
@section('page-title', 'Choose Supplier Portal')
@section('content')
<x-ui.page-header title="Choose Supplier Portal" description="Select the business context for this session." />
<x-ui.card>
    @forelse($scopes as $scope)
        <form method="POST" action="{{ route('supplier-context.store') }}" class="d-inline-block me-2">@csrf
            <input type="hidden" name="context" value="{{ $scope }}">
            <x-ui.button type="submit">{{ $scope === 'import' ? 'Import Procurement' : 'Local Invoices' }}</x-ui.button>
        </form>
    @empty
        <p>No supplier access is configured. Please contact your administrator.</p>
    @endforelse
</x-ui.card>
@endsection
