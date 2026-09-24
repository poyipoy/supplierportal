@extends('layouts.auth')

@section('title', 'Revise Supplier Registration - ADASI Supplier Portal')

@section('content')
<style>
    .auth-form-surface { max-width: 46rem !important; }
    .form-section-title {
        font-size: var(--ui-font-size-sm);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--md-primary);
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
</style>

<header class="tw-mb-4">
    <div class="tw-flex tw-items-center tw-justify-between">
        <div>
            <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Supplier Onboarding</p>
            <h2 class="tw-m-0 tw-mt-1 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">Revise Registration</h2>
        </div>
        <a href="{{ route('supplier.registration.status') }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-3 tw-py-1.5 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-ui-xs tw-font-medium tw-text-on-surface hover:tw-bg-surface-container tw-no-underline">
            <x-ui.icon name="arrow-left" size="xs" />
            <span>Back to Status</span>
        </a>
    </div>
    <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface-variant">Update the requested information and documents below, then resubmit for review.</p>
</header>

{{-- REVIEWER REVISION REASON ALERT --}}
<div class="tw-rounded-ui-sm tw-border tw-border-info/30 tw-bg-info/10 tw-p-4 tw-mb-5">
    <div class="tw-flex tw-items-start tw-gap-3">
        <x-ui.icon name="alert-circle" size="md" class="tw-text-info tw-shrink-0 tw-mt-0.5" />
        <div>
            <h3 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">Reviewer Revision Request</h3>
            <p class="tw-m-0 tw-mt-1 tw-text-ui-sm tw-text-on-surface tw-whitespace-pre-line">{{ $attempt->reviewer_notes ?: 'Please update your details as requested.' }}</p>
        </div>
    </div>
</div>

@if ($errors->any())
    <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3.5 tw-text-on-error-container tw-mb-4" role="alert">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold tw-text-ui-sm tw-mb-1">
            <x-ui.icon name="alert-triangle" size="sm" />
            <span>Please correct the errors below:</span>
        </div>
        <ul class="tw-m-0 tw-pl-5 tw-text-ui-xs tw-space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('supplier.registration.resubmit') }}" enctype="multipart/form-data" class="tw-grid tw-gap-6" x-data="{ companyTitle: '{{ old('company_title', $supplier?->company_title ?? 'PT') }}', isOtherTitle: {{ in_array(old('company_title', $supplier?->company_title), ['PT', 'CV', 'UD', 'PD', 'VPD', 'Firma', 'Koperasi', 'Yayasan', 'Company']) ? 'false' : 'true' }} }">
    @csrf

    {{-- SECTION 1: COMPANY INFORMATION --}}
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <div class="form-section-title">
            <x-ui.icon name="building-2" size="sm" />
            <span>1. Company Profile</span>
        </div>

        <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
            <div class="tw-grid tw-gap-1.5">
                <label for="company_title_select" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Entity Legal Form <span class="tw-text-error">*</span></label>
                <select
                    id="company_title_select"
                    x-model="companyTitle"
                    @change="isOtherTitle = (companyTitle === 'Other')"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary tw-border-outline-variant"
                >
                    <option value="PT">PT (Perseroan Terbatas)</option>
                    <option value="CV">CV (Commanditaire Vennootschap)</option>
                    <option value="UD">UD (Usaha Dagang)</option>
                    <option value="PD">PD (Perusahaan Daerah)</option>
                    <option value="VPD">VPD</option>
                    <option value="Firma">Firma</option>
                    <option value="Koperasi">Koperasi</option>
                    <option value="Yayasan">Yayasan</option>
                    <option value="Company">Company (Foreign/Corp)</option>
                    <option value="Other">Other (Custom Title)</option>
                </select>
                <input type="hidden" name="company_title" :value="companyTitle">
            </div>

            <div class="tw-grid tw-gap-1.5" x-show="isOtherTitle" style="display: none;">
                <label for="custom_company_title" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Specify Legal Entity Title</label>
                <input
                    id="custom_company_title"
                    type="text"
                    name="custom_company_title"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary tw-border-outline-variant"
                    placeholder="e.g. Inc, LLC, Berhad"
                    x-on:input="companyTitle = $el.value"
                    value="{{ old('custom_company_title', $supplier?->company_title) }}"
                >
            </div>

            <div class="tw-grid tw-gap-1.5" :class="isOtherTitle ? 'md:tw-col-span-2' : ''">
                <label for="company_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Company Registered Name <span class="tw-text-error">*</span></label>
                <input
                    id="company_name"
                    type="text"
                    name="company_name"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('company_name') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('company_name', $supplier?->company_name) }}"
                    required
                >
                @error('company_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                <label for="address" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Official Company Address <span class="tw-text-error">*</span></label>
                <textarea
                    id="address"
                    name="address"
                    rows="3"
                    class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-p-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('address') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    required
                >{{ old('address', $supplier?->address) }}</textarea>
                @error('address')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="phone" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Company Phone / Telephone <span class="tw-text-error">*</span></label>
                <input
                    id="phone"
                    type="text"
                    name="phone"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('phone') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('phone', $supplier?->phone) }}"
                    required
                >
                @error('phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="category" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Business Category / Sector</label>
                <input
                    id="category"
                    type="text"
                    name="category"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary tw-border-outline-variant"
                    value="{{ old('category', $supplier?->category) }}"
                >
            </div>

            <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                <label class="tw-flex tw-items-center tw-gap-2.5 tw-cursor-pointer" for="is_pkp">
                    <input type="checkbox" name="is_pkp" id="is_pkp" value="1" class="form-check-input tw-mt-0" {{ old('is_pkp', $supplier?->is_pkp) ? 'checked' : '' }}>
                    <span class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Company is a Registered Taxable Enterprise (Pengusaha Kena Pajak / PKP)</span>
                </label>
            </div>
        </div>
    </div>

    {{-- SECTION 2: TAX & LEGAL IDENTIFICATION --}}
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <div class="form-section-title">
            <x-ui.icon name="file-badge" size="sm" />
            <span>2. Legal & Tax Identification</span>
        </div>

        <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
            <div class="tw-grid tw-gap-1.5">
                <label for="nib" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Nomor Induk Berusaha (NIB) <span class="tw-text-error">*</span></label>
                <input
                    id="nib"
                    type="text"
                    name="nib"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('nib') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('nib', $supplier?->nib) }}"
                    required
                >
                @error('nib')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="npwp" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">NPWP / NIK <span class="tw-text-error">*</span></label>
                <input
                    id="npwp"
                    type="text"
                    name="npwp"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('npwp') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('npwp', $supplier?->npwp) }}"
                    required
                >
                @error('npwp')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>

    {{-- SECTION 3: PIC --}}
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <div class="form-section-title">
            <x-ui.icon name="user" size="sm" />
            <span>3. Person In Charge (PIC)</span>
        </div>

        <div class="tw-grid tw-gap-4 md:tw-grid-cols-3">
            <div class="tw-grid tw-gap-1.5">
                <label for="pic_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">PIC Full Name <span class="tw-text-error">*</span></label>
                <input
                    id="pic_name"
                    type="text"
                    name="pic_name"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('pic_name') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('pic_name', $supplier?->pic_name) }}"
                    required
                >
                @error('pic_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="pic_email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">PIC Email Address <span class="tw-text-error">*</span></label>
                <input
                    id="pic_email"
                    type="email"
                    name="pic_email"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('pic_email') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('pic_email', $supplier?->pic_email) }}"
                    required
                >
                @error('pic_email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="pic_phone" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">PIC Phone / WhatsApp <span class="tw-text-error">*</span></label>
                <input
                    id="pic_phone"
                    type="text"
                    name="pic_phone"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('pic_phone') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('pic_phone', $supplier?->pic_phone) }}"
                    required
                >
                @error('pic_phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>
                @error('pic_phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>

    {{-- SECTION 4: BANK ACCOUNT --}}
    {{-- SECTION 4: OFFICIAL BANK ACCOUNT --}}
    @php
        $currentBank = old('bank_name', $bankAccount?->bank_name ?? '');
        $allStandardBanks = \App\Support\BankList::all();
        $isStandardBank = in_array(strtoupper(trim($currentBank)), array_map('strtoupper', array_map('trim', $allStandardBanks)), true);
        $initialBankSelect = $currentBank === '' ? '' : ($isStandardBank ? $currentBank : 'OTHER');
        $initialOtherBankName = $isStandardBank ? '' : $currentBank;

        $bankOptions = \App\Support\BankList::options($isStandardBank ? $currentBank : null);
        $bankOptions[] = [
            'value' => 'OTHER',
            'label' => 'Bank Lainnya / Other Bank...',
            'sublabel' => 'Pilih untuk mengetik nama bank manual (bank asing/swasta)',
            'badge' => 'Manual',
            'badgeTone' => 'neutral',
            'searchKeywords' => 'lainnya other asing luar negeri manual',
        ];
    @endphp
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <div class="form-section-title">
            <x-ui.icon name="credit-card" size="sm" />
            <span>4. Official Bank Account</span>
        </div>

        <div
            class="tw-grid tw-gap-4 md:tw-grid-cols-3"
            x-data="{
                bankSelect: @js(old('bank_select', $initialBankSelect)),
                otherBankName: @js(old('other_bank_name', $initialOtherBankName)),
                get finalBankName() {
                    return this.bankSelect === 'OTHER' ? this.otherBankName : this.bankSelect;
                },
                onBankChange(event) {
                    this.bankSelect = event.target.value;
                    if (this.bankSelect === 'OTHER') {
                        this.$nextTick(() => {
                            this.$refs.otherBankInput?.focus();
                        });
                    }
                }
            }"
        >
            <input type="hidden" name="bank_name" :value="finalBankName">

            <div class="tw-grid tw-gap-1.5">
                <x-ui.searchable-select
                    name="bank_select"
                    id="bank_select"
                    label="Bank Name"
                    placeholder="Pilih atau cari bank..."
                    search-placeholder="Ketik nama bank (e.g. BCA, Mandiri, BRI)..."
                    :options="$bankOptions"
                    :value="old('bank_select', $initialBankSelect)"
                    :error="$errors->first('bank_name')"
                    required
                    x-on:change="onBankChange($event)"
                />
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="account_number" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Account Number <span class="tw-text-error">*</span></label>
                <input
                    id="account_number"
                    type="text"
                    name="account_number"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('account_number') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('account_number', $bankAccount?->account_number) }}"
                    required
                >
                @error('account_number')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="account_holder_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Account Holder Name <span class="tw-text-error">*</span></label>
                <input
                    id="account_holder_name"
                    type="text"
                    name="account_holder_name"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('account_holder_name') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('account_holder_name', $bankAccount?->account_holder_name) }}"
                    required
                >
                @error('account_holder_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            {{-- Expandable Custom Bank Input with smooth transition --}}
            <div
                x-show="bankSelect === 'OTHER'"
                x-cloak
                x-transition:enter="ui-motion tw-transition-all tw-ease-out tw-duration-200"
                x-transition:enter-start="tw-opacity-0 tw--translate-y-2"
                x-transition:enter-end="tw-opacity-100 tw-translate-y-0"
                x-transition:leave="ui-motion tw-transition-all tw-ease-in tw-duration-150"
                x-transition:leave-start="tw-opacity-100 tw-translate-y-0"
                x-transition:leave-end="tw-opacity-0 tw--translate-y-2"
                class="md:tw-col-span-3 tw-grid tw-gap-1.5 tw-p-3.5 tw-rounded-ui-sm tw-border tw-border-primary/25 tw-bg-primary/5"
            >
                <label for="other_bank_name" class="tw-text-ui-xs tw-font-semibold tw-text-primary tw-flex tw-items-center tw-gap-1.5">
                    <x-ui.icon name="landmark" size="xs" />
                    <span>Nama Bank Lainnya / Other Bank Name <span class="tw-text-error">*</span></span>
                </label>
                <input
                    id="other_bank_name"
                    name="other_bank_name"
                    x-ref="otherBankInput"
                    type="text"
                    x-model="otherBankName"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('bank_name') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    placeholder="Contoh: MUFG Bank, Sumitomo Mitsui, Bank of China, dll."
                    :required="bankSelect === 'OTHER'"
                >
                <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant">
                    Sebutkan nama lengkap bank yang menerbitkan rekening Anda jika tidak terdapat pada daftar di atas.
                </p>
            </div>
        </div>
    </div>

    {{-- SECTION 5: DOCUMENTS REPLACEMENT / UPLOAD --}}
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <div class="form-section-title">
            <x-ui.icon name="file-text" size="sm" />
            <span>5. Verification Documents (Replace or Keep Existing)</span>
        </div>
        <p class="tw-m-0 tw-mb-3 tw-text-ui-xs tw-text-on-surface-variant">
            Upload new files to replace existing documents (PDF, JPG, PNG, Max 5MB). If left blank, previously uploaded documents will be preserved.
        </p>

        <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
            {{-- NIB File --}}
            <div class="tw-grid tw-gap-1.5">
                <div class="tw-flex tw-items-center tw-justify-between">
                    <label for="nib_file" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">NIB Document</label>
                    @if (isset($documents['NIB']))
                        <span class="tw-text-[11px] tw-text-success tw-font-semibold">Current: {{ $documents['NIB']->original_filename }}</span>
                    @endif
                </div>
                <input id="nib_file" type="file" name="nib_file" accept=".pdf,.jpg,.jpeg,.png" class="ui-motion tw-block tw-w-full tw-text-ui-xs tw-text-on-surface-variant file:tw-mr-3 file:tw-py-2 file:tw-px-3 file:tw-rounded-ui-xs file:tw-border-0 file:tw-text-ui-xs file:tw-font-semibold file:tw-bg-primary/10 file:tw-text-primary hover:file:tw-bg-primary/20">
                @error('nib_file')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            {{-- NPWP File --}}
            <div class="tw-grid tw-gap-1.5">
                <div class="tw-flex tw-items-center tw-justify-between">
                    <label for="npwp_file" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">NPWP Document</label>
                    @if (isset($documents['NPWP']))
                        <span class="tw-text-[11px] tw-text-success tw-font-semibold">Current: {{ $documents['NPWP']->original_filename }}</span>
                    @endif
                </div>
                <input id="npwp_file" type="file" name="npwp_file" accept=".pdf,.jpg,.jpeg,.png" class="ui-motion tw-block tw-w-full tw-text-ui-xs tw-text-on-surface-variant file:tw-mr-3 file:tw-py-2 file:tw-px-3 file:tw-rounded-ui-xs file:tw-border-0 file:tw-text-ui-xs file:tw-font-semibold file:tw-bg-primary/10 file:tw-text-primary hover:file:tw-bg-primary/20">
                @error('npwp_file')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            {{-- SKNR File --}}
            <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                <div class="tw-flex tw-items-center tw-justify-between">
                    <label for="sknr_file" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Surat Pernyataan Rekening (SKNR)</label>
                    @if (isset($documents['SURAT_PERNYATAAN_REKENING']))
                        <span class="tw-text-[11px] tw-text-success tw-font-semibold">Current: {{ $documents['SURAT_PERNYATAAN_REKENING']->original_filename }}</span>
                    @endif
                </div>
                <input id="sknr_file" type="file" name="sknr_file" accept=".pdf,.jpg,.jpeg,.png" class="ui-motion tw-block tw-w-full tw-text-ui-xs tw-text-on-surface-variant file:tw-mr-3 file:tw-py-2 file:tw-px-3 file:tw-rounded-ui-xs file:tw-border-0 file:tw-text-ui-xs file:tw-font-semibold file:tw-bg-primary/10 file:tw-text-primary hover:file:tw-bg-primary/20">
                @error('sknr_file')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            {{-- SPPKP File --}}
            <div class="tw-grid tw-gap-1.5">
                <div class="tw-flex tw-items-center tw-justify-between">
                    <label for="sppkp_file" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">SPPKP (Optional)</label>
                    @if (isset($documents['SPPKP']))
                        <span class="tw-text-[11px] tw-text-success tw-font-semibold">Current: {{ $documents['SPPKP']->original_filename }}</span>
                    @endif
                </div>
                <input id="sppkp_file" type="file" name="sppkp_file" accept=".pdf,.jpg,.jpeg,.png" class="ui-motion tw-block tw-w-full tw-text-ui-xs tw-text-on-surface-variant file:tw-mr-3 file:tw-py-2 file:tw-px-3 file:tw-rounded-ui-xs file:tw-border-0 file:tw-text-ui-xs file:tw-font-semibold file:tw-bg-surface-container-high file:tw-text-on-surface hover:file:tw-bg-surface-container-highest">
                @error('sppkp_file')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>

            {{-- SKD File --}}
            <div class="tw-grid tw-gap-1.5">
                <div class="tw-flex tw-items-center tw-justify-between">
                    <label for="skd_file" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">SKD (Optional)</label>
                    @if (isset($documents['SKD']))
                        <span class="tw-text-[11px] tw-text-success tw-font-semibold">Current: {{ $documents['SKD']->original_filename }}</span>
                    @endif
                </div>
                <input id="skd_file" type="file" name="skd_file" accept=".pdf,.jpg,.jpeg,.png" class="ui-motion tw-block tw-w-full tw-text-ui-xs tw-text-on-surface-variant file:tw-mr-3 file:tw-py-2 file:tw-px-3 file:tw-rounded-ui-xs file:tw-border-0 file:tw-text-ui-xs file:tw-font-semibold file:tw-bg-surface-container-high file:tw-text-on-surface hover:file:tw-bg-surface-container-highest">
                @error('skd_file')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </div>

    {{-- SECTION 6: REVISION NOTES --}}
    <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-4">
        <div class="form-section-title">
            <x-ui.icon name="message-square" size="sm" />
            <span>6. Notes for Reviewer (Optional)</span>
        </div>
        <textarea
            id="revision_notes"
            name="revision_notes"
            rows="2"
            class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-p-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary tw-border-outline-variant"
            placeholder="Describe the revisions made or provide additional context for reviewers..."
        >{{ old('revision_notes') }}</textarea>
    </div>

    {{-- SUBMIT REVISED APPLICATION --}}
    <button type="submit" class="ui-focus-ring ui-motion tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        <x-ui.icon name="send" size="sm" />
        <span>Resubmit Revised Registration</span>
    </button>
</form>
@endsection
