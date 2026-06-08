@extends('layouts.app')
@section('title', 'Edit User - ADASI Portal')
@section('page-title', 'Edit User')

@section('content')
    <div class="mb-3">
        <a href="{{ route('admin.users.index') }}" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Back to User List
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">Edit User Form: {{ $user->name }}</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.users.update', $user->id) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="text-primary fw-bold mb-3 border-bottom pb-2">Informasi Akun</h6>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Alamat Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3 border rounded p-3 bg-light">
                            <label class="form-label small fw-bold text-dark"><i class="bi bi-key"></i> Change Password</label>
                            <p class="small text-muted mb-2">Leave blank if you do not want to change the password.</p>
                            
                            <input type="password" name="password" class="form-control mb-2 @error('password') is-invalid @enderror" placeholder="Password Baru (min. 8 karakter)">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            
                            <input type="password" name="password_confirmation" class="form-control" placeholder="Confirm New Password">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Role (Access Rights) <span class="text-danger">*</span></label>
                            <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                                <option value="">-- Select Role --</option>
                                <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Admin</option>
                                <option value="purchasing" {{ old('role', $user->role) == 'purchasing' ? 'selected' : '' }}>Purchasing</option>
                                <option value="supplier" {{ old('role', $user->role) == 'supplier' ? 'selected' : '' }}>Supplier</option>
                                <option value="qc" {{ old('role', $user->role) == 'qc' ? 'selected' : '' }}>Quality Control (QC)</option>
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label small fw-medium text-muted" for="isActive">Active Account</label>
                        </div>
                    </div>

                    {{-- Dinamis untuk Supplier --}}
                    <div class="col-md-6" id="supplier-fields" style="display: none;">
                        <h6 class="text-info fw-bold mb-3 border-bottom pb-2">Company Details (Supplier)</h6>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Company Name (PT/CV) <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name', $user->supplier->company_name ?? '') }}">
                            @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Alamat Lengkap <span class="text-danger">*</span></label>
                            <textarea name="address" class="form-control @error('address') is-invalid @enderror" rows="3">{{ old('address', $user->supplier->address ?? '') }}</textarea>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Number Telepon <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $user->supplier->phone ?? '') }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">NPWP <span class="text-danger">*</span></label>
                            <input type="text" name="npwp" class="form-control @error('npwp') is-invalid @enderror" value="{{ old('npwp', $user->supplier->npwp ?? '') }}">
                            @error('npwp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted">Kategori Material <span class="text-danger">*</span></label>
                            <input type="text" name="category" class="form-control @error('category') is-invalid @enderror" value="{{ old('category', $user->supplier->category ?? '') }}" placeholder="Contoh: Baja, Plat Besi, dsb">
                            @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary fw-medium px-4">
                        <i class="bi bi-save me-1"></i> Save Changes
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
                supplierFields.querySelectorAll('input, textarea, select').forEach(el => el.setAttribute('required', 'required'));
            } else {
                supplierFields.style.display = 'none';
                supplierFields.querySelectorAll('input, textarea, select').forEach(el => el.removeAttribute('required'));
            }
        }

        roleSelect.addEventListener('change', toggleSupplierFields);
        toggleSupplierFields(); // On load
    });
</script>
@endpush
