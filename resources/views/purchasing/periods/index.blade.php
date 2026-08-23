@extends('layouts.app')
@section('uses-datatables', true)
@section('title', 'Period Management - ADASI Portal')
@section('page-title', 'Period Management')

@section('content')
    <div class="tw-grid tw-gap-4">
        {{-- 1. Compact Page Header --}}
        <x-ui.page-header
            title="Quotation Periods"
            eyebrow="Purchasing"
            description="Open and close annual procurement periods that control when requisitions and supplier quotations can be created."
        >
            <x-slot:actions>
                <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#createModal">
                    <x-ui.icon name="plus-circle" size="sm" />
                    <span>Add Period</span>
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- 2. Balanced Data Table --}}
        <x-ui.data-table density="compact">
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="periodsTable">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Period Name</th>
                        <th scope="col">Scope</th>
                        <th scope="col">Year</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col">Created By</th>
                        <th scope="col" class="text-end" style="width: 80px;">Action</th>
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
                        <h6 class="modal-title fw-bold" id="createPeriodModalTitle">Add New Procurement Period</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tw-grid tw-gap-3">
                            <x-ui.input name="year" type="number" label="Year" :value="now()->year" min="2000" required />
                            <x-ui.select name="status" label="Status" helper="PRs and Quotations can only be created in Open periods." required>
                                <option value="open">Open (Accepting Quotations)</option>
                                <option value="closed">Closed (Completed / Archived)</option>
                            </x-ui.select>
                        </div>
                    </div>
                    <div class="modal-footer tw-bg-surface-low border-top">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Cancel</x-ui.button>
                        <x-ui.button type="submit" size="sm">Save Period</x-ui.button>
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
                        <h6 class="modal-title fw-bold" id="editPeriodModalTitle">Edit Procurement Period</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tw-grid tw-gap-3">
                            <x-ui.input name="name" id="editName" label="Period name" required />
                            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2">
                                <x-ui.select name="month" id="editMonth" label="Scope (legacy month optional)" helper="Leave Annual for a year-based procurement period.">
                                    <option value="">Annual</option>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                    @endfor
                                </x-ui.select>
                                <x-ui.input name="year" id="editYear" type="number" label="Year" min="2000" required />
                            </div>
                            <x-ui.select name="status" id="editStatus" label="Status" required>
                                <option value="open">Open (Accepting Quotations)</option>
                                <option value="closed">Closed (Completed / Archived)</option>
                            </x-ui.select>
                        </div>
                    </div>
                    <div class="modal-footer tw-bg-surface-low border-top">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Cancel</x-ui.button>
                        <x-ui.button type="submit" size="sm">Save Changes</x-ui.button>
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
            pageLength: 25,
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
