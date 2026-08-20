@extends('layouts.app')

@section('title', 'Material Requisition List - ADASI Portal')
@section('page-title', 'Purchase Requisition')

@push('styles')
<style>
    .action-button-grid {
        display: inline-grid;
        grid-template-columns: repeat(2, 2.25rem);
        gap: .35rem;
        justify-content: center;
    }

    .action-grid-form {
        margin: 0;
    }

    .action-button-grid .action-grid-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        padding: 0;
    }

    .pr-filter-reset--active {
        background: var(--md-error-container) !important;
        color: var(--md-on-error-container) !important;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Purchase Requisition"
        eyebrow="Purchasing"
        description="Create, filter, and monitor material requisitions across active procurement periods."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('purchasing.export.requisitions')"
                variant="secondary"
                size="sm"
                data-async-export
                id="exportRequisitionsBtn"
                :data-export-url="route('purchasing.export.requisitions')"
            >
                <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> 
                Export Excel
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <i class="bi bi-plus-circle" aria-hidden="true"></i> 
                Create Requisition
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.data-table
        title="Requisition list"
        description="Search the live list or narrow it by period and workflow status."
    >
        <x-slot:filters>
            <x-ui.select name="period_id" id="period_id" label="Period" class="tw-w-full shell:tw-w-72">
                <option value="">All periods</option>
                @foreach($periods as $period)
                    <option value="{{ $period->id }}">
                        {{ $period->display_label }}
                    </option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="status" id="status" label="Status" class="tw-w-full shell:tw-w-56">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="submitted">Submitted</option>
                <option value="rejected">Rejected</option>
                <option value="bidding">Bidding</option>
                <option value="completed">Completed</option>
            </x-ui.select>

            <x-ui.button variant="ghost" size="sm" id="resetFilter" class="pr-filter-reset tw-w-full shell:tw-w-auto">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> 
                Reset filters
            </x-ui.button>

            <div id="filterChips" class="d-none tw-basis-full tw-flex-wrap tw-gap-2" aria-live="polite"></div>
        </x-slot:filters>

        <table class="table table-hover align-middle" id="prTable">
            <thead class="table-light text-center">
                <tr>
                    <th>No</th>
                    <th>PR No.</th>
                    <th>Period</th>
                    <th>Created By</th>
                    <th>Suppliers</th>
                    <th>Items</th>
                    <th>Total KG</th>
                    <th>Status</th>
                    <th>Date Created</th>
                    <th>Action</th>
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
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-medium' },
                { data: 'period_name', name: 'period.name' },
                { data: 'creator_name', name: 'creator.name' },
                { data: 'supplier_count', name: 'invited_suppliers_count', searchable: false, className: 'text-center' },
                { data: 'item_count', name: 'item_count', searchable: false },
                { data: 'total_kg', name: 'total_kg', searchable: false, className: 'text-end ui-tabular-nums' },
                { data: 'status_badge', name: 'status', searchable: false },
                { data: 'created_date', name: 'created_at' },
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
                    class: 'tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-full tw-bg-primary-container tw-px-3 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-text-primary-container-foreground'
                });
                const $remove = $('<button>', {
                    type: 'button',
                    class: 'ui-focus-ring tw-inline-flex tw-h-6 tw-w-6 tw-items-center tw-justify-center tw-rounded-ui-full hover:tw-bg-surface',
                    'aria-label': `Remove ${label} filter`
                }).append($('<i>', { class: 'bi bi-x', 'aria-hidden': 'true' }));

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
                $container.empty().append(chips).removeClass('d-none').addClass('tw-flex');
                $resetBtn.addClass('pr-filter-reset--active');
            } else {
                $container.empty().addClass('d-none').removeClass('tw-flex');
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

        // ADASI Alert delete confirmation (delegated for dynamic rows)
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
