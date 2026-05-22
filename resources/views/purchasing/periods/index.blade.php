@extends('layouts.app')
@section('title', 'Manajemen Periode — ADASI Portal')
@section('page-title', 'Manajemen Periode')

@section('content')
    <div class="row">
        <div class="col-lg-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Daftar Periode Penawaran</h6>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                        <i class="bi bi-plus-lg"></i> Tambah Periode
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="periodsTable" style="font-size: 0.9rem; width: 100%;">
                            <thead class="table-light">
                                <tr>
                                    <th>Nama Periode</th>
                                    <th>Bulan</th>
                                    <th>Tahun</th>
                                    <th>Status</th>
                                    <th>Dibuat Oleh</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('purchasing.periods.store') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Tambah Periode Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Periode</label>
                            <input type="text" name="name" class="form-control" placeholder="Contoh: Periode Mei 2026" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Bulan</label>
                                <select name="month" class="form-select" required>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}" {{ now()->month == $m ? 'selected' : '' }}>
                                            {{ date('F', mktime(0, 0, 0, $m, 1)) }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tahun</label>
                                <input type="number" name="year" class="form-control" value="{{ now()->year }}" min="2000" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="open">Open (Menerima Penawaran)</option>
                                <option value="closed">Closed (Selesai)</option>
                            </select>
                            <div class="form-text">PR hanya bisa dibuat pada periode berstatus Open.</div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Periode</button>
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
                        <h5 class="modal-title fw-bold">Edit Periode</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Periode</label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Bulan</label>
                                <select name="month" id="editMonth" class="form-select" required>
                                    @for($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}">{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tahun</label>
                                <input type="number" name="year" id="editYear" class="form-control" min="2000" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="editStatus" class="form-select" required>
                                <option value="open">Open (Menerima Penawaran)</option>
                                <option value="closed">Closed (Selesai)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
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
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
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
