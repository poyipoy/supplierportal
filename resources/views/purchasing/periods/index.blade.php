@extends('layouts.app')
@section('uses-datatables', true)
@section('title', __('purchasing.titles.periods', []))
@section('page-title', __('purchasing.titles.periods_page', []))

@section('content')
    <div class="tw-grid tw-gap-4">
        {{-- 1. Compact Page Header --}}
        <x-ui.page-header
            :title="__('purchasing.copy.quotation_periods')"
            :eyebrow="__('purchasing.copy.purchasing')"
            :description="__('purchasing.copy.open_and_close_annual_procurement_periods_that_control_when_requisitions_and_supplier_quotations_can')"
        >
            <x-slot:actions>
                <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#createModal">
                    <x-ui.icon name="plus-circle" size="sm" />
                    <span>{{ __('purchasing.copy.add_period') }}</span>
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- 2. Balanced Data Table --}}
        <x-ui.data-table density="compact">
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="periodsTable">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.period_name') }}</th>
                        <th scope="col">{{ __('purchasing.copy.scope') }}</th>
                        <th scope="col">{{ __('purchasing.copy.year') }}</th>
                        <th scope="col" class="text-center">{{ __('purchasing.copy.status') }}</th>
                        <th scope="col">{{ __('purchasing.copy.created_by') }}</th>
                        <th scope="col" class="text-end" style="width: 80px;">{{ __('purchasing.copy.action') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </x-ui.data-table>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createPeriodModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('purchasing.periods.store') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="createPeriodModalTitle">{{ __('purchasing.copy.add_new_procurement_period') }}</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tw-grid tw-gap-3">
                            <x-ui.input name="year" type="number" :label="__('purchasing.copy.year')" :value="now()->year" min="2000" required />
                            <x-ui.select name="status" :label="__('purchasing.copy.status')" :helper="__('purchasing.copy.prs_and_quotations_can_only_be_created_in_open_periods')" required>
                                <option value="open">{{ __('purchasing.copy.open_accepting_quotations') }}</option>
                                <option value="closed">{{ __('purchasing.copy.closed_completed_archived') }}</option>
                            </x-ui.select>
                        </div>
                    </div>
                    <div class="modal-footer tw-bg-surface-low border-top">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('purchasing.copy.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" size="sm">{{ __('purchasing.copy.save_period') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal (Dynamic) -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editPeriodModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="editForm" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold" id="editPeriodModalTitle">{{ __('purchasing.copy.edit_procurement_period') }}</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tw-grid tw-gap-3">
                            <x-ui.input name="name" id="editName" :label="__('purchasing.copy.period_name_922e05')" required />
                            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2">
                                <x-ui.select name="month" id="editMonth" :label="__('purchasing.copy.scope_legacy_month_optional')" :helper="__('purchasing.copy.leave_annual_for_a_year_based_procurement_period')">
                                    <option value="">{{ __('purchasing.copy.annual') }}</option>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                    @endfor
                                </x-ui.select>
                                <x-ui.input name="year" id="editYear" type="number" :label="__('purchasing.copy.year')" min="2000" required />
                            </div>
                            <x-ui.select name="status" id="editStatus" :label="__('purchasing.copy.status')" required>
                                <option value="open">{{ __('purchasing.copy.open_accepting_quotations') }}</option>
                                <option value="closed">{{ __('purchasing.copy.closed_completed_archived') }}</option>
                            </x-ui.select>
                        </div>
                    </div>
                    <div class="modal-footer tw-bg-surface-low border-top">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('purchasing.copy.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" size="sm">{{ __('purchasing.copy.save_changes') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#periodsTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("purchasing.periods.index") }}',
            columns: [
                { data: 'name_display', name: 'name', className: 'fw-bold tw-text-on-surface' },
                { data: 'month_display', name: 'month', className: 'tw-text-on-surface-variant' },
                { data: 'year_display', name: 'year', className: 'tw-text-on-surface-variant' },
                { data: 'status_badge', name: 'status', searchable: false, className: 'text-center' },
                { data: 'creator_name', name: 'creator_name', orderable: false, className: 'tw-text-on-surface-variant' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},

            order: []
        });

        // Handle edit button click (delegated)
        $(document).on('click', '.btn-edit', function() {
            var id = $(this).data('id');
            var baseUrl = '{{ route("purchasing.periods.update", ":id") }}';
            $('#editForm').attr('action', baseUrl.replace(':id', id));
            $('#editName').val($(this).data('name'));
            $('#editMonth').val($(this).data('month'));
            $('#editYear').val($(this).data('year'));
            $('#editStatus').val($(this).data('status'));
            new bootstrap.Modal(document.getElementById('editModal')).show();
        });
    });
</script>
@endpush
