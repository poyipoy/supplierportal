@extends('layouts.app')
@section('title', 'Tambah User — ADASI Portal')
@section('page-title', 'Tambah User')

@section('content')
    <div class="mb-3">
        <a href="{{ route('admin.users.index') }}" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar User
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">Formulir User Baru</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.users.store') }}" method="POST">
                @csrf
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="text-primary fw-bold mb-3 border-bottom pb-2">Informasi Akun</h6>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Alamat Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required minlength="8">
                            <small class="text-muted">Minimal 8 karakter.</small>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Konfirmasi Password <span class="text-danger">*</span></label>
                            <input type="password" name="password_confirmation" class="form-control" required minlength="8">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Role (Hak Akses) <span class="text-danger">*</span></label>
                            <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                                <option value="">-- Pilih Role --</option>
                                <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                                <option value="purchasing" {{ old('role') == 'purchasing' ? 'selected' : '' }}>Purchasing</option>
                                <option value="supplier" {{ old('role') == 'supplier' ? 'selected' : '' }}>Supplier</option>
                                <option value="qc" {{ old('role') == 'qc' ? 'selected' : '' }}>Quality Control (QC)</option>
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                            <label class="form-check-label small fw-medium text-muted" for="isActive">Akun Aktif</label>
                        </div>
                    </div>

                    {{-- Dinamis untuk Supplier --}}
                    <div class="col-md-6" id="supplier-fields" style="display: none;">
                        <h6 class="text-info fw-bold mb-3 border-bottom pb-2">Detail Perusahaan (Supplier)</h6>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Nama Perusahaan (PT/CV) <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name') }}">
                            @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Alamat Lengkap <span class="text-danger">*</span></label>
                            <textarea name="address" class="form-control @error('address') is-invalid @enderror" rows="3">{{ old('address') }}</textarea>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Nomor Telepon <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">NPWP <span class="text-danger">*</span></label>
                            <input type="text" name="npwp" class="form-control @error('npwp') is-invalid @enderror" value="{{ old('npwp') }}">
                            @error('npwp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Kategori Material <span class="text-danger">*</span></label>
                            <input type="text" name="category" class="form-control @error('category') is-invalid @enderror" value="{{ old('category') }}" placeholder="Contoh: Baja, Plat Besi, dsb">
                            @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary fw-medium px-4">
                        <i class="bi bi-save me-1"></i> Simpan User Baru
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleSelect = document.getElementById('role-select');
        const supplierFields = document.getElementById('supplier-fields');

        function toggleSupplierFields() {
            if (roleSelect.value === 'supplier') {
                supplierFields.style.display = 'block';
                // Toggle required
                supplierFields.querySelectorAll('input, textarea').forEach(el => el.setAttribute('required', 'required'));
            } else {
                supplierFields.style.display = 'none';
                // Remove required
                supplierFields.querySelectorAll('input, textarea').forEach(el => el.removeAttribute('required'));
            }
        }

        roleSelect.addEventListener('change', toggleSupplierFields);
        toggleSupplierFields(); // On load
    });
</script>
@endpush
