@extends('layouts.app')
@section('title', 'User Management - ADASI Portal')
@section('page-title', 'User Management')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">User List</h6>
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm fw-medium">
                <i class="bi bi-plus-lg me-1"></i> Add User
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="usersTable" style="font-size: 0.9rem; width: 100%;">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered Since</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var table = $('#usersTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("admin.users.index") }}',
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
                { data: 'name_display', name: 'name' },
                { data: 'email', name: 'email' },
                { data: 'role_badge', name: 'role', searchable: false },
                { data: 'status_badge', name: 'is_active', searchable: false },
                { data: 'created_date', name: 'created_at' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},
            pageLength: 25,
            order: []
        });

        // SweetAlert Delete Confirmation (delegated for dynamic rows)
        $(document).on('click', '.btn-delete', function() {
            const form = $(this).closest('form');
            Swal.fire({
                title: @json('Are you sure you want to delete?'),
                text: @json('This user will be permanently deleted!'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: @json('Yes, delete!'),
                cancelButtonText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
