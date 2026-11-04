@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('claims.copy.material_claims_adasi_portal'))
@section('page-title', __('claims.copy.material_claims'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        :title="__('claims.copy.material_claims')"
        :eyebrow="__('claims.copy.purchasing')"
        :description="__('claims.copy.submit_claims_for_ng_quality_inspections_and_monitor_supplier_resolution_progress')"
    />

    {{-- 2. Queue Tabs --}}
    <x-ui.tab-list
        :label="__('claims.copy.claim_queues')"
        active="action"
        :tabs="[
            ['id' => 'action', 'label' => __('claims.copy.action_required'), 'icon' => 'triangle-alert', 'count' => $actionCount, 'tone' => 'error'],
            ['id' => 'history', 'label' => __('claims.copy.claim_history'), 'icon' => 'history', 'count' => $historyCount],
        ]"
    />

    {{-- 3. Queue Panes --}}
    <div class="tab-content" id="claimTabsContent">
        {{-- Tab 1: Action Required --}}
        <div class="tab-pane fade show active" id="action" role="tabpanel" aria-labelledby="action-tab" tabindex="0">
            <div class="tw:grid tw:gap-3">
                <x-ui.alert tone="warning" :title="__('claims.copy.claim_initiation_required')">{{ __('claims.copy.the_purchase_orders_below_failed_qc_inspection_with_an_ng_result_submit_a_formal_claim_to_initiate_r') }}</x-ui.alert>

                <x-ui.data-table>
                    <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="actionTable">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('claims.copy.po_number') }}</th>
                                <th scope="col">{{ __('claims.copy.supplier') }}</th>
                                <th scope="col">{{ __('claims.copy.inspection_date') }}</th>
                                <th scope="col" class="text-center">{{ __('claims.copy.po_status') }}</th>
                                <th scope="col" class="text-end" style="width: 130px;">{{ __('claims.copy.action') }}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </x-ui.data-table>
            </div>
        </div>

        {{-- Tab 2: Claim History --}}
        <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
            <x-ui.data-table>
                <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="historyTable">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">{{ __('claims.copy.claim_id') }}</th>
                            <th scope="col">{{ __('claims.copy.po_number') }}</th>
                            <th scope="col">{{ __('claims.copy.supplier') }}</th>
                            <th scope="col">{{ __('claims.copy.date_submitted') }}</th>
                            <th scope="col">
                                {{ __('claims.copy.response_deadline') }}
                                <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="{{ __('claims.copy.deadline_for_supplier_to_formally_respond_to_material_claims') }}" />
                            </th>
                            <th scope="col" class="text-center">{{ __('claims.copy.claim_status') }}</th>
                            <th scope="col" class="text-end" style="width: 80px;">{{ __('claims.copy.action') }}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </x-ui.data-table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var dtLang = {};
        var dtOpts = { order: [] };

        $('#actionTable').DataTable(Object.assign({}, dtOpts, {
            processing: true,
            serverSide: true,
            ajax: '{{ route("purchasing.claims.data-action") }}',
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold tw:text-on-surface' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
                { data: 'inspection_date', name: 'inspection_date', orderable: false, searchable: false, className: 'tw:text-on-surface-variant' },
                { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: dtLang
        }));

        var historyInit = false;
        $('button[data-bs-target="#history"]').on('shown.bs.tab', function() {
            if (!historyInit) {
                historyInit = true;
                $('#historyTable').DataTable(Object.assign({}, dtOpts, {
                    processing: true,
                    serverSide: true,
                    ajax: '{{ route("purchasing.claims.data-history") }}',
                    columns: [
                        { data: 'claim_id', name: 'id', className: 'fw-bold tw:text-on-surface' },
                        { data: 'po_number', name: 'po_number', orderable: false },
                        { data: 'supplier_name', name: 'supplier_name', orderable: false },
                        { data: 'created_date', name: 'created_at', className: 'tw:text-on-surface-variant' },
                        { data: 'deadline_display', name: 'deadline', className: 'tw:text-on-surface-variant' },
                        { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                        { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
                    ],
                    language: dtLang,
                    drawCallback: function() {
                        window.initAdasiTooltips?.(document.getElementById('historyTable'));
                    }
                }));
            }
        });
    });
</script>
@endpush
