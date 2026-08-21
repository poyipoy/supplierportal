@extends('layouts.app')
@section('title', 'Add New User - ADASI Portal')
@section('page-title', 'Create User')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.page-header
        title="Add User"
        description="Create an account with the approved role and supplier organization context."
        eyebrow="Admin Users"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" /> Back to User List
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('admin.users.store') }}" method="POST" id="userCreateForm">
        @csrf

        {{-- Section 1: Account & Security Profile --}}
        <x-ui.form-section
            title="Account Identity and Access"
            description="Identity, credentials, role, and activation status for this account."
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-name">
                        Full Name <span class="text-danger">*</span>
                    </label>
                    <input
                        type="text"
                        name="name"
                        id="user-name"
                        class="form-control @error('name') is-invalid @enderror"
                        value="{{ old('name') }}"
                        placeholder="e.g. John Doe"
                        required
                    >
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-email">
                        Email Address <span class="text-danger">*</span>
                    </label>
                    <input
                        type="email"
                        name="email"
                        id="user-email"
                        class="form-control @error('email') is-invalid @enderror"
                        value="{{ old('email') }}"
                        placeholder="e.g. user@astradaido.co.id"
                        required
                    >
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="role-select">
                        Portal Role <span class="text-danger">*</span>
                    </label>
                    <select name="role" id="role-select" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="">-- Select Access Role --</option>
                        <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin (Full System Control)</option>
                        <option value="purchasing" {{ old('role') == 'purchasing' ? 'selected' : '' }}>Purchasing (Requisitions &amp; POs)</option>
                        <option value="supplier" {{ old('role') == 'supplier' ? 'selected' : '' }}>Supplier (Bidding &amp; Quotations)</option>
                        <option value="qc" {{ old('role') == 'qc' ? 'selected' : '' }}>Quality Control (Inspections &amp; Claims)</option>
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="tw-flex tw-items-center tw-pt-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                        <label class="form-check-label tw-text-ui-sm tw-font-medium tw-text-on-surface" for="isActive">
                            Active Account (Allowed to sign in)
                        </label>
                    </div>
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password">
                        Initial Password <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="password"
                        id="user-password"
                        class="form-control @error('password') is-invalid @enderror"
                        required
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="Minimum 12 characters"
                    >
                    <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1 tw-block">Must be at least 12 characters long.</small>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="user-password-confirmation">
                        Confirm Initial Password <span class="text-danger">*</span>
                    </label>
                    <input
                        type="password"
                        name="password_confirmation"
                        id="user-password-confirmation"
                        class="form-control"
                        required
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        placeholder="Re-enter same password"
                    >
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 2: Supplier Company Profile (Conditional) --}}
        <div id="supplier-section" class="{{ old('role') === 'supplier' ? '' : 'd-none' }}">
            <x-ui.form-section
                title="Supplier Organization"
                description="Company identity, contact details, and material category."
            >
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-company-name">
                            Company Legal Name (PT / CV / Corp) <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="company_name"
                            id="supplier-company-name"
                            class="form-control @error('company_name') is-invalid @enderror"
                            value="{{ old('company_name') }}"
                            placeholder="e.g. PT Daido Steel Indah"
                        >
                        @error('company_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-category">
                            Material Supply Category <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="category"
                            id="supplier-category"
                            class="form-control @error('category') is-invalid @enderror"
                            value="{{ old('category') }}"
                            placeholder="e.g. Special Steel, Tool Steel, Rods"
                        >
                        @error('category')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-phone">
                            Phone / Contact Number <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="phone"
                            id="supplier-phone"
                            class="form-control @error('phone') is-invalid @enderror"
                            value="{{ old('phone') }}"
                            placeholder="e.g. +62 21 8934567"
                        >
                        @error('phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-npwp">
                            Tax ID / NPWP <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="npwp"
                            id="supplier-npwp"
                            class="form-control @error('npwp') is-invalid @enderror"
                            value="{{ old('npwp') }}"
                            placeholder="e.g. 01.234.567.8-901.000"
                        >
                        @error('npwp')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="sm:tw-col-span-2">
                        <label class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="supplier-address">
                            Registered Office Address <span class="text-danger">*</span>
                        </label>
                        <textarea
                            name="address"
                            id="supplier-address"
                            class="form-control @error('address') is-invalid @enderror"
                            rows="3"
                            placeholder="Full address of factory or office..."
                        >{{ old('address') }}</textarea>
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar>
            <x-slot:right>
                <x-ui.button :href="route('admin.users.index')" variant="ghost">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    Create User Account
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleSelect = document.getElementById('role-select');
        const supplierSection = document.getElementById('supplier-section');
        const supplierInputs = supplierSection ? supplierSection.querySelectorAll('input, textarea') : [];

        function toggleSupplierFields() {
            if (!roleSelect || !supplierSection) return;
            const isSupplier = roleSelect.value === 'supplier';
            if (isSupplier) {
                supplierSection.classList.remove('d-none');
                supplierInputs.forEach(input => {
                    input.removeAttribute('disabled');
                    input.setAttribute('required', 'required');
                });
            } else {
                supplierSection.classList.add('d-none');
                supplierInputs.forEach(input => {
                    input.setAttribute('disabled', 'disabled');
                    input.removeAttribute('required');
                });
            }
        }

        roleSelect.addEventListener('change', toggleSupplierFields);
        toggleSupplierFields();
    });
</script>
@endpush
