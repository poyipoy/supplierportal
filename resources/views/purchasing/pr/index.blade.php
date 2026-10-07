@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('purchasing.copy.material_requisition_list_adasi_portal'))
@section('page-title', __('purchasing.copy.purchase_requisitions'))

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
        :title="__('purchasing.copy.purchase_requisition')"
        :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.create_filter_and_monitor_material_requisitions_across_active_procurement_periods')"
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('purchasing.export.requisitions')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportRequisitionsBtn"
                :data-export-url="route('purchasing.export.requisitions')"
                data-export-source-singular="{{ __('exports.sources.requisition') }}"
                data-export-source-plural="{{ __('exports.sources.requisitions') }}"
                data-export-count-table="#prTable"
                data-export-row-label="{{ __('purchasing.copy.material_rows') }}"
                data-export-row-explanation="{{ __('purchasing.copy.each_material_item_will_be_written_as_a_separate_excel_row') }}"
            >
                <x-ui.icon name="file-spreadsheet" size="sm" />
                <span>{{ __('purchasing.copy.export_excel') }}</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <x-ui.icon name="plus-circle" size="sm" />
                <span>{{ __('purchasing.copy.create_requisition') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Toolbar --}}
    <x-ui.toolbar :sticky="true">
        <x-slot:filters>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div style="min-width: 200px;">
                    <select name="period_id" id="period_id" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_period') }}">
                        <option value="">{{ __('purchasing.copy.all_periods') }}</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}">{{ $period->display_label }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="min-width: 150px;">
                    <select name="status" id="status" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_status') }}">
                        <option value="">{{ __('purchasing.copy.all_statuses') }}</option>
                        <option value="draft">{{ __('purchasing.copy.draft') }}</option>
                        <option value="submitted">{{ __('purchasing.copy.submitted') }}</option>
                        <option value="rejected">{{ __('purchasing.copy.rejected') }}</option>
                        <option value="bidding">{{ __('purchasing.copy.bidding') }}</option>
                        <option value="completed">{{ __('purchasing.copy.completed') }}</option>
                    </select>
                </div>

                <x-ui.button variant="ghost" size="sm" id="resetFilter" class="pr-filter-reset">
                    <x-ui.icon name="rotate-ccw" />
                    <span>{{ __('purchasing.copy.reset') }}</span>
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
                    <th scope="col" style="width: 40px;">{{ __('purchasing.copy.no') }}</th>
                    <th scope="col">{{ __('purchasing.copy.pr_no') }}</th>
                    <th scope="col">{{ __('purchasing.copy.period') }}</th>
                    <th scope="col">{{ __('purchasing.copy.created_by') }}</th>
                    <th scope="col">{{ __('purchasing.copy.suppliers') }}</th>
                    <th scope="col">{{ __('purchasing.copy.items') }}</th>
                    <th scope="col" class="text-end">{{ __('purchasing.copy.total_kg') }}</th>
                    <th scope="col">{{ __('purchasing.copy.status') }}</th>
                    <th scope="col">{{ __('purchasing.copy.date_created') }}</th>
                    <th scope="col">{{ __('purchasing.copy.action') }}</th>
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
                    'aria-label': window.AdasiI18n.t('js.filters.remove', { label }),
                    text: '×'
                });

                $remove.on('click', () => $(`#${targetId}`).val('').trigger('change'));
                $chip.append(document.createTextNode(label), $remove);

                return $chip;
            };

            const chips = [];
            if (periodText) chips.push(createChip(window.AdasiI18n.t('js.filters.period', { period: periodText }), 'period_id'));
            if (statusText) chips.push(createChip(@js(__('common.final_copy.status')) + ' ' + statusText, 'status'));

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
            const $btn = $(this);
            const form = $btn.closest('form');
            AdasiAlert.confirmDanger({
                title: @json(__('purchasing.copy.are_you_sure_you_want_to_delete')),
                text: @json(__('purchasing.copy.this_material_requisition_will_be_permanently_deleted')),
                confirmText: @json(__('purchasing.audit_ui.confirm_delete')),
                cancelText: @json(__('purchasing.copy.cancel'))
            }).then((result) => {
                if (result.isConfirmed) {
                    window.AdasiButton?.startLoading($btn[0]);
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
                title: @json(__('purchasing.copy.submit_requisition')),
                text: @json(__('purchasing.copy.status_will_change_to_submitted_and_cannot_be_edited_anymore')),
                confirmText: @json(__('purchasing.copy.yes_submit')),
                cancelText: @json(__('purchasing.copy.cancel'))
            }).then((result) => {
                draftSubmitConfirmationOpen = false;

                if (result.isConfirmed) {
                    window.AdasiButton?.startLoading($button[0]);
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
