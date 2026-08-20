@extends('layouts.app')
@section('title', 'Add User - ADASI Portal')
@section('page-title', 'Add User')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Add User" description="Create a role-scoped account and collect supplier company details only when applicable." eyebrow="Admin">
        <x-slot:actions><x-ui.button :href="route('admin.users.index')" variant="ghost" size="sm"><i class="bi bi-arrow-left"></i> Back to User List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="New User Form" description="Passwords require at least 12 characters; role controls the available portal surface.">
            <form action="{{ route('admin.users.store') }}" method="POST">
                @csrf
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="text-primary fw-bold mb-3 border-bottom pb-2">Informasi Akun</h6>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="user-name">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="user-name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="user-email">Alamat Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="user-email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="user-password">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="user-password" class="form-control @error('password') is-invalid @enderror" required minlength="12" maxlength="255" autocomplete="new-password" aria-describedby="user-password-help">
                            <small class="text-muted" id="user-password-help">Minimum 12 characters.</small>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="user-password-confirmation">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="password_confirmation" id="user-password-confirmation" class="form-control" required minlength="12" maxlength="255" autocomplete="new-password">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="role-select">Role (Access Rights) <span class="text-danger">*</span></label>
                            <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                                <option value="">-- Select Role --</option>
                                <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                                <option value="purchasing" {{ old('role') == 'purchasing' ? 'selected' : '' }}>Purchasing</option>
                                <option value="supplier" {{ old('role') == 'supplier' ? 'selected' : '' }}>Supplier</option>
                                <option value="qc" {{ old('role') == 'qc' ? 'selected' : '' }}>Quality Control (QC)</option>
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                            <label class="form-check-label small fw-medium text-muted" for="isActive">Active Account</label>
                        </div>
                    </div>

                    {{-- Dinamis untuk Supplier --}}
                    <div class="col-md-6" id="supplier-fields" hidden>
                        <h6 class="text-info fw-bold mb-3 border-bottom pb-2">Company Details (Supplier)</h6>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="supplier-company-name">Company Name (PT/CV) <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" id="supplier-company-name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name') }}">
                            @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="supplier-address">Alamat Lengkap <span class="text-danger">*</span></label>
                            <textarea name="address" id="supplier-address" class="form-control @error('address') is-invalid @enderror" rows="3">{{ old('address') }}</textarea>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="supplier-phone">Number Telepon <span class="text-danger">*</span></label>
                            <input type="text" name="phone" id="supplier-phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="supplier-npwp">NPWP <span class="text-danger">*</span></label>
                            <input type="text" name="npwp" id="supplier-npwp" class="form-control @error('npwp') is-invalid @enderror" value="{{ old('npwp') }}">
                            @error('npwp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-medium text-muted" for="supplier-category">Kategori Material <span class="text-danger">*</span></label>
                            <input type="text" name="category" id="supplier-category" class="form-control @error('category') is-invalid @enderror" value="{{ old('category') }}" placeholder="Contoh: Baja, Plat Besi, dsb">
                            @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                    </div>
                </div>

                <div class="tw-mt-5 tw-flex tw-justify-end">
                    <x-ui.button type="submit"><i class="bi bi-save"></i> Save New User</x-ui.button>
                </div>
            </form>
    </x-ui.card>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleSelect = document.getElementById('role-select');
        const supplierFields = document.getElementById('supplier-fields');

        function toggleSupplierFields() {
            if (roleSelect.value === 'supplier') {
                supplierFields.hidden = false;
                // Toggle required
                supplierFields.querySelectorAll('input, textarea, select').forEach(el => el.setAttribute('required', 'required'));
            } else {
                supplierFields.hidden = true;
                // Remove required
                supplierFields.querySelectorAll('input, textarea, select').forEach(el => el.removeAttribute('required'));
            }
        }

        roleSelect.addEventListener('change', toggleSupplierFields);
        toggleSupplierFields(); // On load
    });
</script>
@endpush
