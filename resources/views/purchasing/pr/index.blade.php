@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Material Requisition List - ADASI Portal')
@section('page-title', 'Purchase Requisitions')

@push('styles')
<style>
    .pr-filter-reset--active {
        background: var(--md-error-container) !important;
        color: var(--md-on-error-container) !important;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        title="Purchase Requisition"
        eyebrow="Purchasing"
        description="Create, filter, and monitor material requisitions across active procurement periods."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('purchasing.export.requisitions')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportRequisitionsBtn"
                :data-export-url="route('purchasing.export.requisitions')"
            >
                <x-ui.icon name="file-spreadsheet" size="sm" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <x-ui.icon name="plus-circle" size="sm" />
                <span>Create Requisition</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Toolbar --}}
    <x-ui.toolbar :sticky="true">
        <x-slot:filters>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div style="min-width: 200px;">
                    <select name="period_id" id="period_id" class="form-select form-select-sm" aria-label="Filter by Period">
                        <option value="">All Periods</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}">{{ $period->display_label }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="min-width: 150px;">
                    <select name="status" id="status" class="form-select form-select-sm" aria-label="Filter by Status">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                        <option value="rejected">Rejected</option>
                        <option value="bidding">Bidding</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <x-ui.button variant="ghost" size="sm" id="resetFilter" class="pr-filter-reset">
                    <x-ui.icon name="rotate-ccw" />
                    <span>Reset</span>
                </x-ui.button>
            </div>
            <div id="filterChips" class="d-none flex-wrap tw-gap-1.5 align-items-center ms-2" aria-live="polite"></div>
        </x-slot:filters>
    </x-ui.toolbar>

    {{-- 3. Balanced Data Table --}}
    <x-ui.data-table density="compact">
        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="prTable">
            <thead class="table-light text-center">
                <tr>
                    <th scope="col" style="width: 40px;">No</th>
                    <th scope="col">PR No.</th>
                    <th scope="col">Period</th>
                    <th scope="col">Created By</th>
                    <th scope="col">Suppliers</th>
                    <th scope="col">Items</th>
                    <th scope="col" class="text-end">Total KG</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date Created</th>
                    <th scope="col">Action</th>
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
        var table = $('#prTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("purchasing.requisitions.index") }}',
                data: function(d) {
                    d.period_id = $('#period_id').val();
                    d.status = $('#status').val();
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'period_name', name: 'period.name' },
                { data: 'creator_name', name: 'creator.name' },
                { data: 'supplier_count', name: 'invited_suppliers_count', searchable: false, className: 'text-center' },
                { data: 'item_count', name: 'item_count', searchable: false, className: 'text-center' },
                { data: 'total_kg', name: 'total_kg', searchable: false, className: 'text-end fw-semibold tw-text-on-surface ui-tabular-nums' },
                { data: 'status_badge', name: 'status', searchable: false, className: 'text-center' },
                { data: 'created_date', name: 'created_at', className: 'tw-text-on-surface-variant' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
            ],
            language: {},
            pageLength: 25,
            order: []
        });

        $('#exportRequisitionsBtn').on('click', function(event) {
            const exportUrl = new URL(this.dataset.exportUrl, window.location.origin);
            const periodId = $('#period_id').val();
            const status = $('#status').val();
            const search = table.search().trim();

            if (periodId) exportUrl.searchParams.set('period_id', periodId);
            if (status) exportUrl.searchParams.set('status', status);
            if (search) exportUrl.searchParams.set('search', search);

            this.href = exportUrl.toString();
        });

        function updateFilterChips() {
            const periodText = $('#period_id option:selected').val() ? $('#period_id option:selected').text().trim() : null;
            const statusText = $('#status option:selected').val() ? $('#status option:selected').text().trim() : null;

            const createChip = (label, targetId) => {
                const $chip = $('<span>', {
                    class: 'ui-status-chip ui-status-chip--info'
                });
                const $remove = $('<button>', {
                    type: 'button',
                    class: 'ui-focus-ring tw-inline-flex tw-h-5 tw-w-5 tw-items-center tw-justify-center tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-p-0 tw-text-primary hover:tw-bg-primary/10',
                    'aria-label': `Remove ${label} filter`,
                    text: '×'
                });

                $remove.on('click', () => $(`#${targetId}`).val('').trigger('change'));
                $chip.append(document.createTextNode(label), $remove);

                return $chip;
            };

            const chips = [];
            if (periodText) chips.push(createChip(`Period: ${periodText}`, 'period_id'));
            if (statusText) chips.push(createChip(`Status: ${statusText}`, 'status'));

            const $container = $('#filterChips');
            const $resetBtn = $('#resetFilter');

            if (chips.length > 0) {
                $container.empty().append(chips).removeClass('d-none').addClass('d-flex');
                $resetBtn.addClass('pr-filter-reset--active');
            } else {
                $container.empty().addClass('d-none').removeClass('d-flex');
                $resetBtn.removeClass('pr-filter-reset--active');
            }
        }

        // Filter handlers
        $('#period_id, #status').on('change', function() {
            updateFilterChips();
            table.ajax.reload();
        });

        $('#resetFilter').on('click', function() {
            $('#period_id').val('');
            $('#status').val('');
            updateFilterChips();
            table.ajax.reload();
        });

        updateFilterChips();

        // ADASI Alert delete confirmation
        $(document).on('click', '.btn-delete', function() {
            const form = $(this).closest('form');
            AdasiAlert.confirmDanger({
                title: @json('Are you sure you want to delete?'),
                text: @json('This material requisition will be permanently deleted!'),
                confirmText: @json('Yes, delete!'),
                cancelText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });

        let draftSubmitConfirmationOpen = false;
        $(document).on('click', '.btn-submit-draft', function() {
            const $button = $(this);
            if (draftSubmitConfirmationOpen || $button.data('submitting')) {
                return;
            }

            const form = $button.closest('form');
            draftSubmitConfirmationOpen = true;

            AdasiAlert.confirm({
                title: @json('Submit Requisition?'),
                text: @json('Status will change to Submitted and cannot be edited anymore.'),
                confirmText: @json('Yes, Submit!'),
                cancelText: @json('Cancel')
            }).then((result) => {
                draftSubmitConfirmationOpen = false;

                if (result.isConfirmed) {
                    $button.data('submitting', true).prop('disabled', true);
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
