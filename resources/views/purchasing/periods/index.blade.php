@extends('layouts.app')
@section('title', 'Period Management - ADASI Portal')
@section('page-title', 'Period Management')

@section('content')
    <div class="tw-grid tw-gap-6">
        <x-ui.page-header
            title="Quotation periods"
            eyebrow="Purchasing"
            description="Open and close the periods that control when purchase requisitions and supplier quotations can be created."
        >
            <x-slot:actions>
                <x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#createModal">
                    <x-slot:leading><i class="bi bi-plus-lg" aria-hidden="true"></i></x-slot:leading>
                    Add period
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.data-table
            title="Period list"
            description="Search and maintain the month, year, and availability state for each quotation period."
        >
            <table class="table table-hover align-middle" id="periodsTable">
                <thead class="table-light">
                    <tr>
                        <th>Period name</th>
                        <th>Month</th>
                        <th>Year</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </x-ui.data-table>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('purchasing.periods.store') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Add New Period</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tw-grid tw-gap-4">
                            <x-ui.input name="name" label="Period name" placeholder="Example: May 2026 period" required />
                            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                                <x-ui.select name="month" label="Month" required>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}" {{ now()->month == $m ? 'selected' : '' }}>
                                            {{ date('F', mktime(0, 0, 0, $m, 1)) }}
                                        </option>
                                    @endfor
                                </x-ui.select>
                                <x-ui.input name="year" type="number" label="Year" :value="now()->year" min="2000" required />
                            </div>
                            <x-ui.select name="status" label="Status" helper="PR can only be created in a period with Open status." required>
                                <option value="open">Open (Menerima Quotation)</option>
                                <option value="closed">Closed (Completed)</option>
                            </x-ui.select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save period</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal (single, dynamic) -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="editForm" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Period</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tw-grid tw-gap-4">
                            <x-ui.input name="name" id="editName" label="Period name" required />
                            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                                <x-ui.select name="month" id="editMonth" label="Month" required>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                    @endfor
                                </x-ui.select>
                                <x-ui.input name="year" id="editYear" type="number" label="Year" min="2000" required />
                            </div>
                            <x-ui.select name="status" id="editStatus" label="Status" required>
                                <option value="open">Open (Menerima Quotation)</option>
                                <option value="closed">Closed (Completed)</option>
                            </x-ui.select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save changes</x-ui.button>
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
                { data: 'name_display', name: 'name', className: 'fw-medium' },
                { data: 'month_display', name: 'month' },
                { data: 'year_display', name: 'year' },
                { data: 'status_badge', name: 'status', searchable: false },
                { data: 'creator_name', name: 'creator_name', orderable: false },
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
