@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('claims.copy.material_claims_adasi_portal'))
@section('page-title', __('claims.copy.material_claims'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.material_claims') => null,
    ]" />

    <x-ui.page-header
        :title="__('claims.copy.material_claims')"
        :eyebrow="__('claims.copy.quality_management')"
        :description="__('claims.copy.review_and_respond_to_ng_quality_discrepancy_claims_assigned_to_your_supplier_purchase_orders')"
    />

    <x-ui.alert tone="warning" :title="__('claims.copy.response_required')">{{ __('claims.copy.claims_marked_as') }} <strong>{{ __('claims.copy.pending') }}</strong> {{ __('claims.copy.require_your_official_response_and_proposed_resolution_before_the_stated_deadline') }}</x-ui.alert>

    {{-- Claims DataTable --}}
    <x-ui.data-table
        :title="__('claims.copy.discrepancy_claims_from_adasi')"
        :description="__('claims.copy.the_list_is_scoped_strictly_to_purchase_orders_issued_to_your_company')"
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="claimTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('claims.copy.claim_id') }}</th>
                    <th scope="col">{{ __('claims.copy.po_number') }}</th>
                    <th scope="col">{{ __('claims.copy.date_submitted') }}</th>
                    <th scope="col">{{ __('claims.copy.response_deadline') }}</th>
                    <th scope="col" class="text-center">{{ __('claims.copy.status') }}</th>
                    <th scope="col" class="text-end" style="width: 120px;">{{ __('claims.copy.action') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#claimTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.claims.index") }}',
            columns: [
                { data: 'claim_id', name: 'id', className: 'fw-semibold tw-text-on-surface' },
                { data: 'po_number', name: 'po_number', className: 'fw-bold tw-text-on-surface', orderable: false },
                { data: 'created_date', name: 'created_at', className: 'ui-tabular-nums tw-text-on-surface-variant' },
                { data: 'deadline_display', name: 'deadline', className: 'ui-tabular-nums' },
                { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},

            order: [],
            drawCallback: function() {
                window.initAdasiTooltips?.(document.getElementById('claimTable'));
            }
        });
    });
</script>
@endpush
