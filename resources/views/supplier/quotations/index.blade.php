@extends('layouts.app')

@section('title', 'Quotation Period List - ADASI Portal')
@section('page-title', 'Quotation Period')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Quotation Periods" description="Choose a period to review open requisitions and your quotation history." eyebrow="Supplier Portal" />
    <x-ui.data-table title="Select Period" description="Counts show only requisitions and quotations available to your supplier account.">
        <x-slot:toolbar><x-ui.button :href="route('supplier.export.quotations')" variant="secondary" size="sm" data-async-export><i class="bi bi-file-earmark-excel"></i> Export All History</x-ui.button></x-slot:toolbar>
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Period</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Not Responded</th>
                        <th class="text-center">Submitted</th>
                        <th class="text-center">Rejected</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($periods as $period)
                        <tr>
                            <td class="fw-medium ps-3">{{ $period->display_label }}</td>
                            <td class="text-center">
                                <span class="badge {{ $period->status === 'open' ? 'bg-success' : 'bg-secondary' }}">
                                    {{ strtoupper($period->status) }}
                                </span>
                            </td>
                            <td class="text-center">
                                @if($period->unresponded_prs > 0)
                                    <span class="badge bg-danger rounded-pill px-3">{{ $period->unresponded_prs }} PR</span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($period->responded_prs > 0)
                                    <span class="badge bg-success rounded-pill px-3">{{ $period->responded_prs }} PR</span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($period->rejected_prs > 0)
                                    <span class="badge bg-dark rounded-pill px-3">{{ $period->rejected_prs }} PR</span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <x-ui.button :href="route('supplier.quotations.period', $period->id)" size="sm">View Requisitions <i class="bi bi-arrow-right"></i></x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No quotation periods or quotation history.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
    </x-ui.data-table>
</div>
@endsection
