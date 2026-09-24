@extends('layouts.auth')

@section('title', 'Pendaftaran Rekanan Baru (Supplier Registration) - ADASI')

@php
    $currentBank = old('bank_name', '');
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

    // Determine initial active step if returning from validation redirect
    $initialStep = 1;
    if ($errors->any()) {
        $step4Fields = ['cf-turnstile-response'];
        $step3Fields = ['nib_file', 'npwp_file', 'sknr_file', 'sppkp_file', 'skd_file'];
        $step2Fields = ['nib', 'npwp', 'pic_name', 'pic_email', 'pic_phone', 'bank_name', 'bank_select', 'other_bank_name', 'account_number', 'account_holder_name'];
        $errorKeys = $errors->keys();

        if (array_intersect($errorKeys, $step4Fields)) {
            $initialStep = 4;
        } elseif (array_intersect($errorKeys, $step3Fields)) {
            $initialStep = 3;
        } elseif (array_intersect($errorKeys, $step2Fields)) {
            $initialStep = 2;
        } else {
            $initialStep = 1;
        }
    }
@endphp

@section('shell-attributes')
    x-data="supplierRegistrationWizard({{ $initialStep }})"
@endsection

{{-- ════════════════════════════════════════════════════════════════════════════
     LEFT PANEL: ACTIVE ONBOARDING COMPANION (Desktop Sticky)
     ════════════════════════════════════════════════════════════════════════════ --}}
@section('brand-panel')
<aside class="auth-brand-panel tw-relative tw-flex tw-flex-col tw-justify-between tw-overflow-y-auto tw-bg-[#0B1528] tw-text-white tw-p-8 lg:tw-p-10 xl:tw-p-12 tw-sticky tw-top-0 tw-h-screen tw-z-10" aria-label="Panduan & Progres Pendaftaran">
    {{-- Subtle industrial ambient background overlay --}}
    <div class="tw-absolute tw-inset-0 tw-z-0 tw-opacity-15 tw-pointer-events-none">
        <img src="{{ asset('assets/images/adasi-login-bg.jpg') }}" alt="" class="tw-w-full tw-h-full tw-object-cover" draggable="false">
    </div>
    <div class="tw-absolute tw-inset-0 tw-z-0 tw-bg-gradient-to-b tw-from-[#0B1528]/95 tw-via-[#0B1528]/85 tw-to-[#0B1528]/95 tw-pointer-events-none"></div>

    {{-- Brand & Header Section --}}
    <div class="tw-relative tw-z-10">
        <div class="tw-flex tw-items-center tw-gap-3">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="ADASI Logo" class="tw-h-9 tw-w-auto tw-shrink-0" draggable="false">
            <div>
                <div class="tw-text-ui-xs tw-font-bold tw-tracking-wider tw-text-white/90 tw-uppercase">PT Astra Daido Steel Indonesia</div>
                <div class="tw-text-[11px] tw-font-medium tw-text-white/60">Supplier Onboarding Portal</div>
            </div>
        </div>

        <div class="tw-mt-8">
            <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-[11px] tw-font-semibold tw-bg-primary/20 tw-text-primary-200 tw-border tw-border-primary/30">
                <x-ui.icon name="shield-check" size="xs" />
                <span>Pendaftaran Vendor Resmi</span>
            </span>
            <h1 class="tw-text-ui-xl xl:tw-text-ui-2xl tw-font-bold tw-text-white tw-mt-2 tw-leading-tight">
                Bergabung dalam Jaringan Rantai Pasok ADASI
            </h1>
            <p class="tw-text-ui-xs tw-text-white/70 tw-mt-2 tw-leading-relaxed tw-max-w-md">
                Daftarkan legalitas entitas perusahaan Anda untuk bermitra dalam pengadaan material baja impor & lokal berstandar Astra.
            </p>
        </div>

        {{-- Vertical Stepper Tracker --}}
        <div class="tw-mt-8 tw-space-y-4" role="navigation" aria-label="Tahapan Pendaftaran">
            {{-- Step 1 Item --}}
            <div
                class="tw-flex tw-items-start tw-gap-3.5 tw-cursor-pointer tw-group"
                @click="goToStep(1)"
            >
                <div class="tw-flex tw-flex-col tw-items-center tw-shrink-0">
                    <div
                        class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-ui-xs tw-font-bold tw-transition-all"
                        :class="{
                            'tw-bg-success tw-text-white': step > 1,
                            'tw-bg-primary tw-text-white tw-ring-4 tw-ring-primary/25 tw-scale-105': step === 1,
                            'tw-bg-white/10 tw-text-white/50 tw-border tw-border-white/20': step < 1
                        }"
                    >
                        <template x-if="step > 1">
                            <x-ui.icon name="check" size="xs" />
                        </template>
                        <template x-if="step <= 1">
                            <span>1</span>
                        </template>
                    </div>
                    <div class="tw-w-0.5 tw-h-10 tw-my-1" :class="step > 1 ? 'tw-bg-success/50' : 'tw-bg-white/15'"></div>
                </div>
                <div class="tw-pt-1">
                    <div class="tw-text-ui-xs tw-font-semibold tw-transition-colors" :class="step === 1 ? 'tw-text-white tw-font-bold' : (step > 1 ? 'tw-text-white/90' : 'tw-text-white/50')">
                        1. Akun & Profil Perusahaan
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">Kredensial login, legalitas entitas & kontak</div>
                </div>
            </div>

            {{-- Step 2 Item --}}
            <div
                class="tw-flex tw-items-start tw-gap-3.5 tw-cursor-pointer tw-group"
                @click="goToStep(2)"
            >
                <div class="tw-flex tw-flex-col tw-items-center tw-shrink-0">
                    <div
                        class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-ui-xs tw-font-bold tw-transition-all"
                        :class="{
                            'tw-bg-success tw-text-white': step > 2,
                            'tw-bg-primary tw-text-white tw-ring-4 tw-ring-primary/25 tw-scale-105': step === 2,
                            'tw-bg-white/10 tw-text-white/50 tw-border tw-border-white/20': step < 2
                        }"
                    >
                        <template x-if="step > 2">
                            <x-ui.icon name="check" size="xs" />
                        </template>
                        <template x-if="step <= 2">
                            <span>2</span>
                        </template>
                    </div>
                    <div class="tw-w-0.5 tw-h-10 tw-my-1" :class="step > 2 ? 'tw-bg-success/50' : 'tw-bg-white/15'"></div>
                </div>
                <div class="tw-pt-1">
                    <div class="tw-text-ui-xs tw-font-semibold tw-transition-colors" :class="step === 2 ? 'tw-text-white tw-font-bold' : (step > 2 ? 'tw-text-white/90' : 'tw-text-white/50')">
                        2. Identitas Legal, PIC & Bank
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">NIB, NPWP, kontak narahubung & rekening bank</div>
                </div>
            </div>

            {{-- Step 3 Item --}}
            <div
                class="tw-flex tw-items-start tw-gap-3.5 tw-cursor-pointer tw-group"
                @click="goToStep(3)"
            >
                <div class="tw-flex tw-flex-col tw-items-center tw-shrink-0">
                    <div
                        class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-ui-xs tw-font-bold tw-transition-all"
                        :class="{
                            'tw-bg-success tw-text-white': step > 3,
                            'tw-bg-primary tw-text-white tw-ring-4 tw-ring-primary/25 tw-scale-105': step === 3,
                            'tw-bg-white/10 tw-text-white/50 tw-border tw-border-white/20': step < 3
                        }"
                    >
                        <template x-if="step > 3">
                            <x-ui.icon name="check" size="xs" />
                        </template>
                        <template x-if="step <= 3">
                            <span>3</span>
                        </template>
                    </div>
                    <div class="tw-w-0.5 tw-h-10 tw-my-1" :class="step > 3 ? 'tw-bg-success/50' : 'tw-bg-white/15'"></div>
                </div>
                <div class="tw-pt-1">
                    <div class="tw-text-ui-xs tw-font-semibold tw-transition-colors" :class="step === 3 ? 'tw-text-white tw-font-bold' : (step > 3 ? 'tw-text-white/90' : 'tw-text-white/50')">
                        3. Berkas Dokumen Verifikasi
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">Unggah salinan NIB, NPWP, SKNR, SPPKP & SKD</div>
                </div>
            </div>

            {{-- Step 4 Item --}}
            <div
                class="tw-flex tw-items-start tw-gap-3.5 tw-cursor-pointer tw-group"
                @click="goToStep(4)"
            >
                <div class="tw-flex tw-flex-col tw-items-center tw-shrink-0">
                    <div
                        class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-ui-xs tw-font-bold tw-transition-all"
                        :class="{
                            'tw-bg-primary tw-text-white tw-ring-4 tw-ring-primary/25 tw-scale-105': step === 4,
                            'tw-bg-white/10 tw-text-white/50 tw-border tw-border-white/20': step < 4
                        }"
                    >
                        <span>4</span>
                    </div>
                </div>
                <div class="tw-pt-1">
                    <div class="tw-text-ui-xs tw-font-semibold tw-transition-colors" :class="step === 4 ? 'tw-text-white tw-font-bold' : 'tw-text-white/50'">
                        4. Tinjau & Kirim Pendaftaran
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">Pemeriksaan pra-kirim & konfirmasi legalitas</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Contextual Guidance Card (Dynamically updates per step) --}}
    <div class="tw-relative tw-z-10 tw-my-6">
        <div class="tw-p-4 tw-rounded-ui-md tw-bg-white/5 tw-border tw-border-white/10 tw-backdrop-blur-sm">
            {{-- Step 1 Tip --}}
            <div x-show="step === 1" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="info" size="xs" />
                    <span>Panduan Kredensial & Profil</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                    Email yang Anda daftarkan akan menjadi <strong>username resmi</strong> untuk mengakses sistem setelah disetujui. Pastikan nama perusahaan sesuai persis dengan akta resmi atau NIB terbaru.
                </p>
            </div>

            {{-- Step 2 Tip --}}
            <div x-show="step === 2" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="credit-card" size="xs" />
                    <span>Ketentuan Rekening & Identitas</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                    NIB harus berupa <strong>13 digit angka</strong>. Nama pemilik rekening bank <strong>wajib sesuai</strong> dengan nama entitas legal perusahaan Anda demi kepatuhan perpajakan dan transaksi keuangan ADASI.
                </p>
            </div>

            {{-- Step 3 Tip --}}
            <div x-show="step === 3" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="file-text" size="xs" />
                    <span>Ketentuan Dokumen Legalitas</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                    <strong>SKNR</strong> adalah surat pernyataan/referensi rekening resmi bertanda tangan & stempel perusahaan atau dari pihak bank. Berkas berformat <strong>PDF, JPG, atau PNG</strong> dengan ukuran maks. <strong>5 MB</strong> per berkas.
                </p>
            </div>

            {{-- Step 4 Tip --}}
            <div x-show="step === 4" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="shield-check" size="xs" />
                    <span>Proses Verifikasi Internal</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                    Setelah dikirim, pendaftaran akan diverifikasi oleh tim Pengadaan dan Keuangan ADASI. Anda akan diberikan <strong>Nomor Registrasi</strong> & <strong>Kode Akses</strong> untuk memantau status secara mandiri.
                </p>
            </div>
        </div>
    </div>

    {{-- Bottom Support Card --}}
    <div class="tw-relative tw-z-10 tw-pt-4 tw-border-t tw-border-white/10 tw-text-ui-xs tw-text-white/60">
        <div class="tw-flex tw-items-center tw-justify-between">
            <div>
                <div class="tw-text-[11px] tw-font-medium tw-text-white/40">Bantuan Registrasi:</div>
                <div class="tw-text-white/90 tw-font-semibold tw-mt-0.5">procurement@astra-daido.co.id</div>
            </div>
            <a href="{{ route('login') }}" class="tw-text-primary-300 hover:tw-text-primary-200 tw-text-ui-xs tw-font-semibold tw-underline">
                Portal Sign In
            </a>
        </div>
        <div class="tw-mt-3 tw-text-[10px] tw-text-white/40">
            &copy; {{ now()->year }} PT Astra Daido Steel Indonesia. Hak Cipta Dilindungi.
        </div>
    </div>
</aside>
@endsection

{{-- ════════════════════════════════════════════════════════════════════════════
     RIGHT PANEL: PROGRESSIVE WIZARD FORM
     ════════════════════════════════════════════════════════════════════════════ --}}
@section('content')
<style>
    @media (min-width: 1024px) {
        .auth-shell {
            grid-template-columns: 380px 1fr !important;
        }
        .auth-brand-panel {
            overflow-y: auto !important;
            max-height: 100vh !important;
            position: sticky !important;
            top: 0 !important;
        }
        .auth-form-panel {
            padding: 2.5rem 3rem !important;
            align-items: stretch !important;
            justify-content: flex-start !important;
        }
        .auth-form-surface {
            max-width: 56rem !important;
            width: 100% !important;
            margin: 0 auto !important;
        }
    }
    @media (min-width: 1440px) {
        .auth-shell {
            grid-template-columns: 420px 1fr !important;
        }
        .auth-form-surface {
            max-width: 60rem !important;
        }
    }
    .auth-form-surface { max-width: 54rem; width: 100%; }
    .form-step-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.625rem;
        border-radius: var(--md-shape-full);
        font-size: var(--ui-font-size-xs);
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: var(--md-primary-container);
        color: var(--md-on-primary-container);
    }
</style>

{{-- Mobile Compact Stepper Header (Visible only on < 1024px) --}}
<div class="lg:tw-hidden tw-mb-5 tw-pb-4 tw-border-b tw-border-outline-variant">
    <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
        <span class="tw-text-ui-xs tw-font-bold tw-uppercase tw-tracking-wider tw-text-primary">
            Langkah <span x-text="step"></span> dari 4
        </span>
        <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" x-text="stepTitles[step]"></span>
    </div>

    {{-- Visual Progress Bar --}}
    <div class="tw-w-full tw-h-2 tw-rounded-full tw-bg-surface-container-high tw-overflow-hidden">
        <div
            class="tw-h-full tw-bg-primary tw-transition-all tw-duration-300"
            :style="'width: ' + ((step / 4) * 100) + '%'"
        ></div>
    </div>
</div>

{{-- Main Form Header --}}
<header class="tw-mb-6">
    <div class="tw-flex tw-items-center tw-justify-between tw-gap-3">
        <div class="form-step-badge">
            <x-ui.icon name="layers" size="xs" />
            <span>Langkah <span x-text="step"></span> / 4</span>
        </div>
        <span class="tw-text-ui-xs tw-text-on-surface-variant">
            Kolom bertanda <span class="tw-text-error tw-font-bold">*</span> wajib diisi
        </span>
    </div>

    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-xl lg:tw-text-ui-2xl tw-font-bold tw-tracking-tight tw-text-on-surface" x-text="stepHeadings[step]">
        Akun Portal & Profil Perusahaan
    </h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant" x-text="stepSubheadings[step]">
        Lengkapi kredensial akses dan profil legal perusahaan Anda untuk memulai proses onboarding rekanan ADASI.
    </p>
</header>

{{-- Draft Restore Banner (Visible when unsubmitted draft is detected in localStorage) --}}
<div
    x-show="hasDraft && step === 1"
    x-cloak
    class="tw-mb-5 tw-p-3.5 tw-rounded-ui-sm tw-border tw-border-primary/30 tw-bg-primary/5 tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3"
>
    <div class="tw-flex tw-items-start tw-gap-2.5">
        <x-ui.icon name="history" size="sm" class="tw-text-primary tw-shrink-0 tw-mt-0.5" />
        <div>
            <div class="tw-text-ui-xs tw-font-bold tw-text-on-surface">Draf Pengisian Ditemukan</div>
            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                Tersimpan draf data teks dari sesi sebelumnya pada perangkat ini. Ingin memulihkan data tersebut?
            </div>
        </div>
    </div>
    <div class="tw-flex tw-items-center tw-gap-2 tw-shrink-0">
        <button
            type="button"
            class="ui-motion ui-focus-ring tw-px-3 tw-py-1.5 tw-rounded-ui-xs tw-bg-primary tw-text-white tw-text-ui-xs tw-font-semibold hover:tw-brightness-95 active:tw-scale-95"
            @click="restoreDraft()"
        >
            Pulihkan Draf
        </button>
        <button
            type="button"
            class="ui-motion ui-focus-ring tw-px-2.5 tw-py-1.5 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-on-surface-variant tw-text-ui-xs hover:tw-bg-surface-container"
            @click="discardDraft()"
        >
            Abaikan
        </button>
    </div>
</div>

{{-- Server-Side Error Alert --}}
@if ($errors->any())
    <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3.5 tw-text-on-error-container tw-mb-5" role="alert">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold tw-text-ui-sm tw-mb-1">
            <x-ui.icon name="alert-triangle" size="sm" />
            <span>Terdapat beberapa kesalahan input yang perlu diperbaiki:</span>
        </div>
        <ul class="tw-m-0 tw-pl-5 tw-text-ui-xs tw-space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Client-side General Error Toast/Notice if validation fails on step change --}}
<div
    x-show="clientStepError"
    x-cloak
    class="tw-mb-4 tw-p-3 tw-rounded-ui-sm tw-bg-error-container/80 tw-text-on-error-container tw-flex tw-items-center tw-justify-between tw-gap-2 tw-text-ui-xs"
>
    <div class="tw-flex tw-items-center tw-gap-2">
        <x-ui.icon name="alert-circle" size="sm" class="tw-text-error" />
        <span x-text="clientStepError"></span>
    </div>
    <button type="button" @click="clientStepError = ''" class="tw-text-on-error-container hover:tw-opacity-70">
        <x-ui.icon name="x" size="xs" />
    </button>
</div>

<form
    id="supplierRegistrationForm"
    method="POST"
    action="{{ route('supplier.register.store') }}"
    enctype="multipart/form-data"
    @submit="handleSubmit($event)"
    novalidate
>
    @csrf

    {{-- ════════════════════════════════════════════════════════════════════════
         STEP 1: ACCOUNT CREDENTIALS & COMPANY PROFILE
         ════════════════════════════════════════════════════════════════════════ --}}
    <div x-show="step === 1" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 1.1: Portal Account Credentials --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="key" size="sm" />
                <span>1. Kredensial Akun Portal (Login)</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                Email dan kata sandi ini akan menjadi identitas akses resmi Anda ke portal rekanan ADASI.
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                {{-- Official Company Email --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                    <label for="email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Alamat Email Resmi Perusahaan <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="mail" size="sm" />
                        </div>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            x-model="formData.email"
                            @blur="validateField('email')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.email ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="contoh: procurement@perusahaan.co.id"
                            required
                        >
                    </div>
                    <p x-show="errors.email" x-text="errors.email" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Password with dynamic validation checklist --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Kata Sandi (Password) <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" />
                        </div>
                        <input
                            id="password"
                            :type="showPass ? 'text' : 'password'"
                            name="password"
                            x-model="formData.password"
                            @input="validateField('password'); if (formData.password_confirmation) validateField('password_confirmation');"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.password ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="Minimal 8 karakter"
                            required
                        >
                        <button
                            type="button"
                            class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                            @click="showPass = !showPass"
                            tabindex="-1"
                            :aria-label="showPass ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                        >
                            <x-ui.icon name="eye" size="sm" x-show="!showPass" />
                            <x-ui.icon name="eye-off" size="sm" x-show="showPass" />
                        </button>
                    </div>

                    {{-- Dynamic Password Strength Criteria Checklist --}}
                    <div class="tw-mt-1 tw-flex tw-flex-wrap tw-gap-x-3 tw-gap-y-1 tw-text-[11px]">
                        <span class="tw-flex tw-items-center tw-gap-1" :class="passwordValidLength ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'">
                            <x-ui.icon name="check-circle" size="xs" x-show="passwordValidLength" />
                            <x-ui.icon name="circle" size="xs" x-show="!passwordValidLength" />
                            <span>Min. 8 karakter</span>
                        </span>
                        <span class="tw-flex tw-items-center tw-gap-1" :class="passwordHasLetter ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'">
                            <x-ui.icon name="check-circle" size="xs" x-show="passwordHasLetter" />
                            <x-ui.icon name="circle" size="xs" x-show="!passwordHasLetter" />
                            <span>Mengandung huruf</span>
                        </span>
                        <span class="tw-flex tw-items-center tw-gap-1" :class="passwordHasNumber ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'">
                            <x-ui.icon name="check-circle" size="xs" x-show="passwordHasNumber" />
                            <x-ui.icon name="circle" size="xs" x-show="!passwordHasNumber" />
                            <span>Mengandung angka</span>
                        </span>
                    </div>

                    <p x-show="errors.password" x-text="errors.password" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('password')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Password Confirmation --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="password_confirmation" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Konfirmasi Kata Sandi <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" />
                        </div>
                        <input
                            id="password_confirmation"
                            :type="showConfirmPass ? 'text' : 'password'"
                            name="password_confirmation"
                            x-model="formData.password_confirmation"
                            @input="validateField('password_confirmation')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.password_confirmation ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="Ketik ulang kata sandi"
                            required
                        >
                        <button
                            type="button"
                            class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                            @click="showConfirmPass = !showConfirmPass"
                            tabindex="-1"
                            :aria-label="showConfirmPass ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                        >
                            <x-ui.icon name="eye" size="sm" x-show="!showConfirmPass" />
                            <x-ui.icon name="eye-off" size="sm" x-show="showConfirmPass" />
                        </button>
                    </div>
                    <p x-show="errors.password_confirmation" x-text="errors.password_confirmation" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                </div>
            </div>
        </div>

        {{-- Section 1.2: Company Profile & Identity --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="building-2" size="sm" />
                <span>2. Profil & Identitas Perusahaan</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                Informasi legalitas entitas sesuai Akta Pendirian Perusahaan dan perizinan berusaha aktif.
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-3">
                {{-- Entity Legal Form --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <label for="company_title_select" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Bentuk Badan Hukum <span class="tw-text-error">*</span>
                    </label>
                    <select
                        id="company_title_select"
                        x-model="formData.company_title"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                    >
                        <option value="PT">PT (Perseroan Terbatas)</option>
                        <option value="CV">CV (Commanditaire Vennootschap)</option>
                        <option value="UD">UD (Usaha Dagang)</option>
                        <option value="PD">PD (Perusahaan Daerah)</option>
                        <option value="VPD">VPD</option>
                        <option value="Firma">Firma</option>
                        <option value="Koperasi">Koperasi</option>
                        <option value="Yayasan">Yayasan</option>
                        <option value="Company">Company (Foreign / Corp)</option>
                        <option value="Other">Lainnya / Other Title...</option>
                    </select>
                    <input type="hidden" name="company_title" :value="formData.company_title">
                </div>

                {{-- Custom Entity Title (Shown when 'Other' selected) --}}
                <div
                    class="tw-grid tw-gap-1.5 md:tw-col-span-2"
                    x-show="formData.company_title === 'Other'"
                    x-cloak
                >
                    <label for="custom_company_title" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Sebutkan Bentuk Badan Hukum Khusus <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="custom_company_title"
                        type="text"
                        name="custom_company_title"
                        x-model="formData.custom_company_title"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        placeholder="contoh: LLC, Inc, Berhad, Pte Ltd"
                    >
                </div>

                {{-- Registered Company Name --}}
                <div class="tw-grid tw-gap-1.5" :class="formData.company_title === 'Other' ? 'md:tw-col-span-3' : 'md:tw-col-span-2'">
                    <label for="company_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Nama Resmi Perusahaan <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="company_name"
                        type="text"
                        name="company_name"
                        x-model="formData.company_name"
                        @blur="validateField('company_name')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.company_name ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="contoh: Astra Daido Steel Indonesia"
                        required
                    >
                    <p x-show="errors.company_name" x-text="errors.company_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('company_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Official Company Address --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-3">
                    <label for="address" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Alamat Domisili Legal Perusahaan <span class="tw-text-error">*</span>
                    </label>
                    <textarea
                        id="address"
                        name="address"
                        rows="3"
                        x-model="formData.address"
                        @blur="validateField('address')"
                        class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-p-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.address ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="Alamat lengkap sesuai NIB / NPWP perusahaan (Jalan, Kawasan Industri, Gedung, Kota, Kode Pos)"
                        required
                    ></textarea>
                    <p x-show="errors.address" x-text="errors.address" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('address')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Company Phone / Telephone --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                    <label for="phone" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Nomor Telepon Kantor / Perusahaan <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="phone" size="sm" />
                        </div>
                        <input
                            id="phone"
                            type="text"
                            name="phone"
                            x-model="formData.phone"
                            @blur="validateField('phone')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.phone ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="021-xxxxxxxx atau +6221xxxx"
                            required
                        >
                    </div>
                    <p x-show="errors.phone" x-text="errors.phone" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Business Category / Sector --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <label for="category" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Sektor / Kategori Usaha
                    </label>
                    <input
                        id="category"
                        type="text"
                        name="category"
                        x-model="formData.category"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        placeholder="contoh: Special Steel, Machining, General"
                    >
                </div>

                {{-- PKP Status Card --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-3">
                    <label
                        class="tw-flex tw-items-center tw-gap-3 tw-p-3.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-cursor-pointer hover:tw-bg-surface-container-high/30 tw-transition-colors"
                        for="is_pkp"
                    >
                        <input
                            type="checkbox"
                            name="is_pkp"
                            id="is_pkp"
                            value="1"
                            x-model="formData.is_pkp"
                            class="form-check-input tw-mt-0 tw-w-4 tw-h-4"
                        >
                        <div>
                            <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                                Perusahaan Terdaftar sebagai Pengusaha Kena Pajak (PKP)
                            </span>
                            <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                Centang jika perusahaan menerbitkan Faktur Pajak resmi (SPPKP akan diunggah pada tahap dokumen).
                            </p>
                        </div>
                    </label>
                </div>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════════
         STEP 2: LEGAL & TAX IDENTIFICATION, PIC & OFFICIAL BANK
         ════════════════════════════════════════════════════════════════════════ --}}
    <div x-show="step === 2" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 2.1: Legal & Tax Identification --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="file-badge" size="sm" />
                <span>3. Identitas Legalitas & Perpajakan</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                Nomor identitas perpajakan dan izin berusaha resmi yang terdaftar di sistem pemerintah Republik Indonesia.
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                {{-- NIB Input with live digit counter --}}
                <div class="tw-grid tw-gap-1.5">
                    <div class="tw-flex tw-items-center tw-justify-between">
                        <label for="nib" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                            Nomor Induk Berusaha (NIB) <span class="tw-text-error">*</span>
                        </label>
                        <span
                            class="tw-text-[11px] tw-font-mono tw-font-semibold tw-tabular-nums"
                            :class="formData.nib.replace(/\D/g, '').length === 13 ? 'tw-text-success' : 'tw-text-on-surface-variant'"
                            x-text="formData.nib.replace(/\D/g, '').length + '/13 digit'"
                        ></span>
                    </div>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="hash" size="sm" />
                        </div>
                        <input
                            id="nib"
                            type="text"
                            name="nib"
                            x-model="formData.nib"
                            @blur="validateField('nib')"
                            @input="formData.nib = formData.nib.replace(/\D/g, '').slice(0, 13); validateField('nib');"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-font-mono tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.nib ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="13 digit angka NIB OSS"
                            maxlength="13"
                            required
                        >
                    </div>
                    <p x-show="errors.nib" x-text="errors.nib" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('nib')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- NPWP Input with live digit counter --}}
                <div class="tw-grid tw-gap-1.5">
                    <div class="tw-flex tw-items-center tw-justify-between">
                        <label for="npwp" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                            NPWP / NIK (Nomor Pokok Wajib Pajak) <span class="tw-text-error">*</span>
                        </label>
                        <span
                            class="tw-text-[11px] tw-font-mono tw-font-semibold tw-tabular-nums"
                            :class="[15, 16].includes(formData.npwp.replace(/\D/g, '').length) ? 'tw-text-success' : 'tw-text-on-surface-variant'"
                            x-text="formData.npwp.replace(/\D/g, '').length + '/16 digit'"
                        ></span>
                    </div>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="file-text" size="sm" />
                        </div>
                        <input
                            id="npwp"
                            type="text"
                            name="npwp"
                            x-model="formData.npwp"
                            @blur="validateField('npwp')"
                            @input="formData.npwp = formData.npwp.replace(/\D/g, '').slice(0, 16); validateField('npwp');"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-font-mono tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.npwp ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="15 atau 16 digit NPWP"
                            maxlength="16"
                            required
                        >
                    </div>
                    <p x-show="errors.npwp" x-text="errors.npwp" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('npwp')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 2.2: Person In Charge (PIC) --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="user" size="sm" />
                <span>4. Narahubung Resmi / Person In Charge (PIC)</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                Petugas resmi perusahaan yang berwenang dalam korespondensi penawaran, purchase order, dan penagihan.
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-3">
                {{-- PIC Full Name --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="pic_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Nama Lengkap PIC <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="pic_name"
                        type="text"
                        name="pic_name"
                        x-model="formData.pic_name"
                        @blur="validateField('pic_name')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.pic_name ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="Nama penanggung jawab"
                        required
                    >
                    <p x-show="errors.pic_name" x-text="errors.pic_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('pic_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- PIC Email Address --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="pic_email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Alamat Email PIC <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="pic_email"
                        type="email"
                        name="pic_email"
                        x-model="formData.pic_email"
                        @blur="validateField('pic_email')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.pic_email ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="pic@perusahaan.co.id"
                        required
                    >
                    <p x-show="errors.pic_email" x-text="errors.pic_email" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('pic_email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- PIC Phone / WhatsApp --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="pic_phone" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        No. HP / WhatsApp PIC <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="pic_phone"
                        type="text"
                        name="pic_phone"
                        x-model="formData.pic_phone"
                        @blur="validateField('pic_phone')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.pic_phone ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="08xxxxxxxxxx"
                        required
                    >
                    <p x-show="errors.pic_phone" x-text="errors.pic_phone" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('pic_phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 2.3: Official Bank Account --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="credit-card" size="sm" />
                <span>5. Rekening Bank Resmi Perusahaan</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                Rekening bank operasional yang akan digunakan untuk proses pembayaran. Nama pemilik rekening <strong>wajib sesuai</strong> dengan Surat Keterangan Nomor Rekening (SKNR).
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-3">
                <input type="hidden" name="bank_name" :value="finalBankName">

                {{-- Bank Select --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <x-ui.searchable-select
                        name="bank_select"
                        id="bank_select"
                        label="Nama Bank"
                        placeholder="Pilih atau cari bank..."
                        search-placeholder="Ketik nama bank (contoh: BCA, Mandiri, BRI)..."
                        :options="$bankOptions"
                        :value="old('bank_select', $initialBankSelect)"
                        :error="$errors->first('bank_name')"
                        required
                        x-on:change="onBankChange($event)"
                    />
                </div>

                {{-- Account Number --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <label for="account_number" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Nomor Rekening Bank <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="account_number"
                        type="text"
                        name="account_number"
                        x-model="formData.account_number"
                        @blur="validateField('account_number')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-font-mono tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.account_number ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="Nomor rekening tanpa spasi"
                        required
                    >
                    <p x-show="errors.account_number" x-text="errors.account_number" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('account_number')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Beneficiary Name --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <label for="account_holder_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        Nama Pemilik Rekening (Atas Nama) <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="account_holder_name"
                        type="text"
                        name="account_holder_name"
                        x-model="formData.account_holder_name"
                        @blur="validateField('account_holder_name')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.account_holder_name ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="Sesuai buku tabungan / SKNR"
                        required
                    >
                    <p x-show="errors.account_holder_name" x-text="errors.account_holder_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('account_holder_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Expandable Custom Bank Input (when 'OTHER' is selected) --}}
                <div
                    x-show="formData.bank_select === 'OTHER'"
                    x-cloak
                    x-transition:enter="ui-motion tw-transition-all tw-ease-out tw-duration-200"
                    x-transition:enter-start="tw-opacity-0 tw--translate-y-2"
                    x-transition:enter-end="tw-opacity-100 tw-translate-y-0"
                    class="md:tw-col-span-3 tw-grid tw-gap-1.5 tw-p-3.5 tw-rounded-ui-sm tw-border tw-border-primary/25 tw-bg-primary/5"
                >
                    <label for="other_bank_name" class="tw-text-ui-xs tw-font-semibold tw-text-primary tw-flex tw-items-center tw-gap-1.5">
                        <x-ui.icon name="landmark" size="xs" />
                        <span>Sebutkan Nama Bank Lainnya <span class="tw-text-error">*</span></span>
                    </label>
                    <input
                        id="other_bank_name"
                        name="other_bank_name"
                        x-ref="otherBankInput"
                        type="text"
                        x-model="formData.other_bank_name"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        placeholder="contoh: MUFG Bank, Sumitomo Mitsui, Bank of China, dsb."
                        :required="formData.bank_select === 'OTHER'"
                    >
                    <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant">
                        Ketik nama lengkap bank yang menerbitkan rekening jika tidak tertera pada opsi pencarian di atas.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════════
         STEP 3: VERIFICATION DOCUMENTS (DRAG & DROP ZONES)
         ════════════════════════════════════════════════════════════════════════ --}}
    <div x-show="step === 3" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="file-text" size="sm" />
                    <span>6. Berkas Dokumen Verifikasi Rekanan</span>
                </div>
                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                    <x-ui.icon name="lock" size="xs" />
                    <span>Private Storage Terenkripsi</span>
                </span>
            </div>
            <p class="tw-m-0 tw-mb-5 tw-text-ui-xs tw-text-on-surface-variant">
                Unggah salinan digital resmi dokumen legalitas perusahaan Anda dalam format <strong>PDF, JPG, atau PNG</strong> (maksimal <strong>5 MB</strong> per berkas).
            </p>

            {{-- 3.1 Mandatory Documents --}}
            <div class="tw-mb-5">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3">
                    <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-bold tw-bg-error/10 tw-text-error">
                        Dokumen Wajib (Mandatory)
                    </span>
                    <span class="tw-text-[11px] tw-text-on-surface-variant">3 dokumen wajib dilampirkan sebelum mengirim pendaftaran</span>
                </div>

                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                    {{-- NIB File Dropzone --}}
                    <x-ui.file-upload
                        name="nib_file"
                        id="nib_file"
                        label="1. Dokumen NIB (Nomor Induk Berusaha)"
                        helper="Salinan digital NIB resmi 13 digit yang diterbitkan oleh sistem OSS."
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="true"
                    />

                    {{-- NPWP File Dropzone --}}
                    <x-ui.file-upload
                        name="npwp_file"
                        id="npwp_file"
                        label="2. Dokumen NPWP Perusahaan"
                        helper="Salinan kartu NPWP atau Surat Keterangan Terdaftar (SKT) Pajak."
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="true"
                    />

                    {{-- SKNR File Dropzone (Full Width Featured) --}}
                    <div class="md:tw-col-span-2">
                        <x-ui.file-upload
                            name="sknr_file"
                            id="sknr_file"
                            label="3. Surat Keterangan / Pernyataan Nomor Rekening (SKNR)"
                            helper="Surat resmi berkop surat perusahaan bermeterai atau surat referensi bank yang menerangkan kepemilikan rekening resmi."
                            accept=".pdf,.jpg,.jpeg,.png"
                            :max-size-mb="5"
                            :required="true"
                        />
                    </div>
                </div>
            </div>

            {{-- 3.2 Optional Documents --}}
            <div class="tw-pt-4 tw-border-t tw-border-outline-variant">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3">
                    <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-bold tw-bg-surface-high tw-text-on-surface-variant">
                        Dokumen Tambahan (Opsional)
                    </span>
                    <span class="tw-text-[11px] tw-text-on-surface-variant">Lampirkan bila relevan dengan status pajak atau domisili perusahaan</span>
                </div>

                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                    {{-- SPPKP File Dropzone --}}
                    <x-ui.file-upload
                        name="sppkp_file"
                        id="sppkp_file"
                        label="Surat Pengukuhan PKP (SPPKP)"
                        helper="Wajib dilampirkan apabila perusahaan Anda berstatus Pengusaha Kena Pajak."
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="false"
                    />

                    {{-- SKD File Dropzone --}}
                    <x-ui.file-upload
                        name="skd_file"
                        id="skd_file"
                        label="Surat Keterangan Domisili (SKD)"
                        helper="Lampirkan jika domisili operasional pabrik/kantor berbeda dengan NIB."
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="false"
                    />
                </div>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════════
         STEP 4: PRE-FLIGHT REVIEW & CONFIRMATION
         ════════════════════════════════════════════════════════════════════════ --}}
    <div x-show="step === 4" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 4 Header --}}
        <div class="tw-p-4 tw-rounded-ui-md tw-bg-primary/10 tw-border tw-border-primary/20 tw-flex tw-items-start tw-gap-3">
            <x-ui.icon name="check-circle" size="md" class="tw-text-primary tw-shrink-0 tw-mt-0.5" />
            <div>
                <div class="tw-text-ui-sm tw-font-bold tw-text-primary">Tinjau Ringkasan Pendaftaran (Pre-flight Review)</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                    Harap periksa kembali seluruh data berikut. Menghindari kesalahan data akan mempercepat proses review dan aktivasi vendor master.
                </div>
            </div>
        </div>

        {{-- Summary Card 1: Akun & Profil Perusahaan --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="building-2" size="sm" class="tw-text-primary" />
                    <span>Profil Perusahaan & Akun</span>
                </div>
                <button
                    type="button"
                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-primary/10"
                    @click="goToStep(1)"
                >
                    <x-ui.icon name="pencil" size="xs" />
                    <span>Ubah Data</span>
                </button>
            </div>

            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 tw-text-ui-xs">
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Nama Lengkap Perusahaan:</span>
                    <span class="tw-font-bold tw-text-on-surface" x-text="(formData.company_title === 'Other' ? formData.custom_company_title : formData.company_title) + ' ' + formData.company_name"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Email Akun Portal:</span>
                    <span class="tw-font-mono tw-font-semibold tw-text-on-surface" x-text="formData.email"></span>
                </div>
                <div class="sm:tw-col-span-2">
                    <span class="tw-text-on-surface-variant tw-block">Alamat Domisili Legal:</span>
                    <span class="tw-text-on-surface" x-text="formData.address"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">No. Telepon Kantor:</span>
                    <span class="tw-font-mono tw-text-on-surface" x-text="formData.phone"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Sektor Usaha & Pajak:</span>
                    <span class="tw-text-on-surface" x-text="(formData.category || 'Umum') + ' · ' + (formData.is_pkp ? 'Status PKP' : 'Non-PKP')"></span>
                </div>
            </div>
        </div>

        {{-- Summary Card 2: Legalitas, PIC & Rekening Bank --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="credit-card" size="sm" class="tw-text-primary" />
                    <span>Identitas Legal, PIC & Rekening Bank</span>
                </div>
                <button
                    type="button"
                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-primary/10"
                    @click="goToStep(2)"
                >
                    <x-ui.icon name="pencil" size="xs" />
                    <span>Ubah Data</span>
                </button>
            </div>

            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3 tw-text-ui-xs">
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Nomor Induk Berusaha (NIB):</span>
                    <span class="tw-font-mono tw-font-bold tw-text-on-surface" x-text="formData.nib"></span>
                </div>
                <div class="sm:tw-col-span-2">
                    <span class="tw-text-on-surface-variant tw-block">NPWP / NIK Perusahaan:</span>
                    <span class="tw-font-mono tw-font-bold tw-text-on-surface" x-text="formData.npwp"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Narahubung (PIC):</span>
                    <span class="tw-font-semibold tw-text-on-surface" x-text="formData.pic_name"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Email PIC:</span>
                    <span class="tw-font-mono tw-text-on-surface" x-text="formData.pic_email"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">HP / WhatsApp PIC:</span>
                    <span class="tw-font-mono tw-text-on-surface" x-text="formData.pic_phone"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Bank Operasional:</span>
                    <span class="tw-font-semibold tw-text-on-surface" x-text="finalBankName"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Nomor Rekening:</span>
                    <span class="tw-font-mono tw-font-bold tw-text-on-surface" x-text="formData.account_number"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">Atas Nama (Beneficiary):</span>
                    <span class="tw-font-semibold tw-text-on-surface" x-text="formData.account_holder_name"></span>
                </div>
            </div>
        </div>

        {{-- Summary Card 3: Dokumen Verifikasi --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="file-text" size="sm" class="tw-text-primary" />
                    <span>Daftar Berkas Dokumen Terlampir</span>
                </div>
                <button
                    type="button"
                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-primary/10"
                    @click="goToStep(3)"
                >
                    <x-ui.icon name="pencil" size="xs" />
                    <span>Ubah Dokumen</span>
                </button>
            </div>

            <div class="tw-space-y-2 tw-text-ui-xs">
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">1. Dokumen NIB</span>
                    <span class="tw-font-mono tw-text-primary" x-text="docs.nib ? (docs.nib.name + ' (' + docs.nib.size + ')') : '✓ Siap diunggah'"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">2. Dokumen NPWP</span>
                    <span class="tw-font-mono tw-text-primary" x-text="docs.npwp ? (docs.npwp.name + ' (' + docs.npwp.size + ')') : '✓ Siap diunggah'"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">3. Surat Pernyataan Rekening (SKNR)</span>
                    <span class="tw-font-mono tw-text-primary" x-text="docs.sknr ? (docs.sknr.name + ' (' + docs.sknr.size + ')') : '✓ Siap diunggah'"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">4. Surat Pengukuhan PKP (SPPKP)</span>
                    <span class="tw-text-on-surface-variant" x-text="docs.sppkp ? (docs.sppkp.name + ' (' + docs.sppkp.size + ')') : 'Tidak dilampirkan'"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">5. Surat Keterangan Domisili (SKD)</span>
                    <span class="tw-text-on-surface-variant" x-text="docs.skd ? (docs.skd.name + ' (' + docs.skd.size + ')') : 'Tidak dilampirkan'"></span>
                </div>
            </div>
        </div>

        {{-- Legal Declaration Statement --}}
        <div class="tw-p-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-low">
            <label class="tw-flex tw-items-start tw-gap-3 tw-cursor-pointer" for="legal_declaration">
                <input
                    type="checkbox"
                    id="legal_declaration"
                    x-model="legalDeclaration"
                    class="form-check-input tw-mt-0.5 tw-w-4 tw-h-4 tw-shrink-0"
                    required
                >
                <div class="tw-text-ui-xs tw-text-on-surface tw-leading-relaxed">
                    <strong>Pernyataan Kebenaran Data:</strong>
                    Saya menyatakan dengan sesungguhnya bahwa seluruh data, identitas legalitas, rekening bank, dan salinan dokumen yang disampaikan di atas adalah benar, sah, dan dapat dipertanggungjawabkan secara hukum di wilayah Republik Indonesia.
                </div>
            </label>
        </div>

        {{-- Bot Protection Turnstile if configured --}}
        @if (config('auth_security.turnstile.site_key'))
            <div class="tw-flex tw-flex-col tw-items-center tw-pt-2">
                <div class="cf-turnstile" data-sitekey="{{ config('auth_security.turnstile.site_key') }}"></div>
                @error('cf-turnstile-response')<p class="tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif
    </div>

    {{-- ════════════════════════════════════════════════════════════════════════
         BOTTOM WIZARD ACTION NAVIGATION BAR
         ════════════════════════════════════════════════════════════════════════ --}}
    <div class="tw-mt-8 tw-pt-5 tw-border-t tw-border-outline-variant tw-flex tw-items-center tw-justify-between tw-gap-4">
        {{-- Previous Button --}}
        <div>
            <button
                type="button"
                x-show="step > 1"
                @click="prevStep()"
                class="ui-motion ui-focus-ring tw-inline-flex tw-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-5 tw-text-ui-sm tw-font-semibold tw-text-on-surface hover:tw-bg-surface-container active:tw-scale-95"
            >
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali</span>
            </button>
        </div>

        {{-- Step Indicator Center --}}
        <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">
            Langkah <span x-text="step"></span> dari 4
        </div>

        {{-- Next or Submit Button --}}
        <div>
            {{-- Next Step Button --}}
            <button
                type="button"
                x-show="step < 4"
                @click="nextStep()"
                class="ui-motion ui-focus-ring tw-inline-flex tw-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-6 tw-text-ui-sm tw-font-semibold tw-text-white hover:tw-brightness-95 active:tw-scale-95"
            >
                <span>Lanjutkan</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </button>

            {{-- Final Submit Button --}}
            <button
                type="submit"
                x-show="step === 4"
                :disabled="!legalDeclaration || isSubmitting"
                class="ui-motion ui-focus-ring tw-inline-flex tw-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-6 tw-text-ui-sm tw-font-semibold tw-text-white hover:tw-brightness-95 active:tw-scale-95 disabled:tw-opacity-50 disabled:tw-pointer-events-none"
            >
                <template x-if="isSubmitting">
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <span>Mengirimkan Pendaftaran...</span>
                    </div>
                </template>
                <template x-if="!isSubmitting">
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <x-ui.icon name="send" size="sm" />
                        <span>Kirim Pendaftaran Supplier</span>
                    </div>
                </template>
            </button>
        </div>
    </div>
</form>

<div class="tw-mt-6 tw-pt-4 tw-border-t tw-border-outline-variant tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
    <p class="tw-m-0">
        Sudah pernah mendaftar?
        <a href="{{ route('supplier.registration.access-form') }}" class="tw-font-semibold tw-text-primary hover:tw-underline">
            Cek status pendaftaran Anda
        </a>
    </p>
    <p class="tw-m-0 tw-mt-1.5">
        <a href="{{ route('login') }}" class="tw-text-on-surface-variant hover:tw-text-primary hover:tw-underline">
            Kembali ke Portal Sign In
        </a>
    </p>
</div>
@endsection

@section('scripts')
@if (config('auth_security.turnstile.site_key'))
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('supplierRegistrationWizard', (initialStep = 1) => ({
        step: initialStep,
        maxStep: 4,
        isSubmitting: false,
        legalDeclaration: false,
        hasDraft: false,
        clientStepError: '',

        stepTitles: {
            1: 'Akun & Profil',
            2: 'Legal, PIC & Bank',
            3: 'Dokumen Verifikasi',
            4: 'Tinjau & Kirim'
        },

        stepHeadings: {
            1: 'Akun Portal & Profil Perusahaan',
            2: 'Identitas Legal, PIC & Rekening Bank',
            3: 'Dokumen Verifikasi Legalitas',
            4: 'Tinjau Ringkasan & Konfirmasi Pendaftaran'
        },

        stepSubheadings: {
            1: 'Lengkapi kredensial akses dan profil legal perusahaan Anda untuk memulai proses onboarding.',
            2: 'Masukkan nomor identitas perpajakan resmi, kontak narahubung operasional, dan rekening bank.',
            3: 'Unggah salinan dokumen resmi untuk verifikasi kepatuhan dan pencatatan master data vendor.',
            4: 'Periksa kembali seluruh data dan kelengkapan berkas sebelum dikirimkan ke tim pengadaan ADASI.'
        },

        formData: {
            email: @js(old('email', '')),
            password: '',
            password_confirmation: '',
            company_title: @js(old('company_title', 'PT')),
            custom_company_title: @js(old('custom_company_title', '')),
            company_name: @js(old('company_name', '')),
            address: @js(old('address', '')),
            phone: @js(old('phone', '')),
            category: @js(old('category', '')),
            is_pkp: @js((bool) old('is_pkp', false)),
            nib: @js(old('nib', '')),
            npwp: @js(old('npwp', '')),
            pic_name: @js(old('pic_name', '')),
            pic_email: @js(old('pic_email', '')),
            pic_phone: @js(old('pic_phone', '')),
            bank_select: @js(old('bank_select', $initialBankSelect)),
            other_bank_name: @js(old('other_bank_name', $initialOtherBankName)),
            account_number: @js(old('account_number', '')),
            account_holder_name: @js(old('account_holder_name', '')),
        },

        errors: {},

        docs: {
            nib: null,
            npwp: null,
            sknr: null,
            sppkp: null,
            skd: null,
        },

        showPass: false,
        showConfirmPass: false,

        get finalBankName() {
            return this.formData.bank_select === 'OTHER'
                ? this.formData.other_bank_name
                : this.formData.bank_select;
        },

        get passwordValidLength() {
            return this.formData.password.length >= 8;
        },

        get passwordHasLetter() {
            return /[A-Za-z]/.test(this.formData.password);
        },

        get passwordHasNumber() {
            return /[0-9]/.test(this.formData.password);
        },

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        onBankChange(event) {
            this.formData.bank_select = event.target.value;
            if (this.formData.bank_select === 'OTHER') {
                this.$nextTick(() => {
                    this.$refs.otherBankInput?.focus();
                });
            }
            this.validateField('bank_name');
            this.persistDraft();
        },

        init() {
            // Check local storage draft
            this.checkStoredDraft();

            // Set up document change tracking for review summary
            const docMap = {
                nib_file: 'nib',
                npwp_file: 'npwp',
                sknr_file: 'sknr',
                sppkp_file: 'sppkp',
                skd_file: 'skd',
            };

            this.$nextTick(() => {
                Object.keys(docMap).forEach(id => {
                    const input = document.getElementById(id);
                    if (input) {
                        input.addEventListener('change', (e) => {
                            const file = e.target.files?.[0];
                            const key = docMap[id];
                            this.docs[key] = file ? { name: file.name, size: this.formatBytes(file.size) } : null;
                            if (this.errors[id]) delete this.errors[id];
                        });
                    }
                });
            });

            // Watch fields for debounced draft persistence
            this.$watch('formData', () => {
                this.persistDraft();
            });
        },

        checkStoredDraft() {
            try {
                const stored = localStorage.getItem('adasi_supplier_reg_draft');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    if (parsed && typeof parsed === 'object' && Object.keys(parsed).length > 2) {
                        this.hasDraft = true;
                    }
                }
            } catch (e) {
                this.hasDraft = false;
            }
        },

        restoreDraft() {
            try {
                const stored = localStorage.getItem('adasi_supplier_reg_draft');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    Object.keys(parsed).forEach(key => {
                        if (key in this.formData) {
                            this.formData[key] = parsed[key];
                        }
                    });
                    this.hasDraft = false;
                    if (window.AdasiToast) {
                        window.AdasiToast.success('Draf formulir berhasil dipulihkan.');
                    }
                }
            } catch (e) {
                console.warn('Failed to restore draft:', e);
            }
        },

        discardDraft() {
            try {
                localStorage.removeItem('adasi_supplier_reg_draft');
                this.hasDraft = false;
                if (window.AdasiToast) {
                    window.AdasiToast.info('Draf formulir telah dihapus.');
                }
            } catch (e) {
                this.hasDraft = false;
            }
        },

        persistDraft() {
            try {
                // Never store passwords in localStorage
                const safePayload = {
                    company_title: this.formData.company_title,
                    custom_company_title: this.formData.custom_company_title,
                    company_name: this.formData.company_name,
                    address: this.formData.address,
                    phone: this.formData.phone,
                    category: this.formData.category,
                    is_pkp: this.formData.is_pkp,
                    nib: this.formData.nib,
                    npwp: this.formData.npwp,
                    pic_name: this.formData.pic_name,
                    pic_email: this.formData.pic_email,
                    pic_phone: this.formData.pic_phone,
                    bank_select: this.formData.bank_select,
                    other_bank_name: this.formData.other_bank_name,
                    account_number: this.formData.account_number,
                    account_holder_name: this.formData.account_holder_name,
                };
                localStorage.setItem('adasi_supplier_reg_draft', JSON.stringify(safePayload));
            } catch (e) {
                // Storage quota or private mode restrictions
            }
        },

        validateField(field) {
            delete this.errors[field];

            if (field === 'email') {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!this.formData.email || !this.formData.email.trim()) {
                    this.errors.email = 'Alamat email resmi perusahaan wajib diisi.';
                } else if (!re.test(this.formData.email.trim())) {
                    this.errors.email = 'Format alamat email tidak valid.';
                }
            }

            if (field === 'password') {
                if (!this.formData.password) {
                    this.errors.password = 'Kata sandi wajib diisi.';
                } else if (this.formData.password.length < 8) {
                    this.errors.password = 'Kata sandi minimal 8 karakter.';
                } else if (!this.passwordHasLetter || !this.passwordHasNumber) {
                    this.errors.password = 'Kata sandi harus mengandung kombinasi huruf dan angka.';
                }
            }

            if (field === 'password_confirmation') {
                if (!this.formData.password_confirmation) {
                    this.errors.password_confirmation = 'Konfirmasi kata sandi wajib diisi.';
                } else if (this.formData.password !== this.formData.password_confirmation) {
                    this.errors.password_confirmation = 'Konfirmasi kata sandi tidak cocok.';
                }
            }

            if (field === 'company_name') {
                if (!this.formData.company_name || !this.formData.company_name.trim()) {
                    this.errors.company_name = 'Nama resmi perusahaan wajib diisi.';
                }
            }

            if (field === 'address') {
                if (!this.formData.address || !this.formData.address.trim()) {
                    this.errors.address = 'Alamat legal perusahaan wajib diisi.';
                }
            }

            if (field === 'phone') {
                if (!this.formData.phone || !this.formData.phone.trim()) {
                    this.errors.phone = 'Nomor telepon kantor wajib diisi.';
                }
            }

            if (field === 'nib') {
                const digits = this.formData.nib.replace(/\D/g, '');
                if (!digits) {
                    this.errors.nib = 'NIB wajib diisi.';
                } else if (digits.length !== 13) {
                    this.errors.nib = 'NIB harus tepat 13 digit angka (saat ini ' + digits.length + ' digit).';
                }
            }

            if (field === 'npwp') {
                const digits = this.formData.npwp.replace(/\D/g, '');
                if (!digits) {
                    this.errors.npwp = 'NPWP / NIK wajib diisi.';
                } else if (![15, 16].includes(digits.length)) {
                    this.errors.npwp = 'NPWP harus terdiri dari 15 atau 16 digit angka (saat ini ' + digits.length + ' digit).';
                }
            }

            if (field === 'pic_name') {
                if (!this.formData.pic_name || !this.formData.pic_name.trim()) {
                    this.errors.pic_name = 'Nama lengkap PIC wajib diisi.';
                }
            }

            if (field === 'pic_email') {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!this.formData.pic_email || !this.formData.pic_email.trim()) {
                    this.errors.pic_email = 'Email PIC wajib diisi.';
                } else if (!re.test(this.formData.pic_email.trim())) {
                    this.errors.pic_email = 'Format email PIC tidak valid.';
                }
            }

            if (field === 'pic_phone') {
                if (!this.formData.pic_phone || !this.formData.pic_phone.trim()) {
                    this.errors.pic_phone = 'No. HP/WhatsApp PIC wajib diisi.';
                }
            }

            if (field === 'bank_name') {
                if (!this.finalBankName || !this.finalBankName.trim()) {
                    this.errors.bank_name = 'Nama bank wajib dipilih atau diisi.';
                }
            }

            if (field === 'account_number') {
                if (!this.formData.account_number || !this.formData.account_number.trim()) {
                    this.errors.account_number = 'Nomor rekening bank wajib diisi.';
                }
            }

            if (field === 'account_holder_name') {
                if (!this.formData.account_holder_name || !this.formData.account_holder_name.trim()) {
                    this.errors.account_holder_name = 'Nama pemilik rekening wajib diisi.';
                }
            }
        },

        validateStep(stepNumber) {
            this.clientStepError = '';

            if (stepNumber === 1) {
                this.validateField('email');
                this.validateField('password');
                this.validateField('password_confirmation');
                this.validateField('company_name');
                this.validateField('address');
                this.validateField('phone');

                const hasError = ['email', 'password', 'password_confirmation', 'company_name', 'address', 'phone']
                    .some(k => this.errors[k]);

                if (hasError) {
                    this.clientStepError = 'Harap periksa dan lengkapi kolom yang wajib diisi pada langkah ini.';
                    return false;
                }
                return true;
            }

            if (stepNumber === 2) {
                this.validateField('nib');
                this.validateField('npwp');
                this.validateField('pic_name');
                this.validateField('pic_email');
                this.validateField('pic_phone');
                this.validateField('bank_name');
                this.validateField('account_number');
                this.validateField('account_holder_name');

                const hasError = ['nib', 'npwp', 'pic_name', 'pic_email', 'pic_phone', 'bank_name', 'account_number', 'account_holder_name']
                    .some(k => this.errors[k]);

                if (hasError) {
                    this.clientStepError = 'Harap lengkapi nomor legalitas, PIC, dan rekening bank dengan benar.';
                    return false;
                }
                return true;
            }

            if (stepNumber === 3) {
                // Check required file attachments
                const nibInput = document.getElementById('nib_file');
                const npwpInput = document.getElementById('npwp_file');
                const sknrInput = document.getElementById('sknr_file');

                const missingDocs = [];
                if (!nibInput || !nibInput.files || nibInput.files.length === 0) {
                    missingDocs.push('NIB');
                }
                if (!npwpInput || !npwpInput.files || npwpInput.files.length === 0) {
                    missingDocs.push('NPWP');
                }
                if (!sknrInput || !sknrInput.files || sknrInput.files.length === 0) {
                    missingDocs.push('SKNR');
                }

                if (missingDocs.length > 0) {
                    this.clientStepError = 'Dokumen wajib belum lengkap: ' + missingDocs.join(', ') + '. Silakan unggah dokumen tersebut untuk melanjutkan.';
                    return false;
                }
                return true;
            }

            return true;
        },

        updateDocsSummary() {
            const docMap = {
                nib_file: 'nib',
                npwp_file: 'npwp',
                sknr_file: 'sknr',
                sppkp_file: 'sppkp',
                skd_file: 'skd',
            };
            Object.keys(docMap).forEach(id => {
                const input = document.getElementById(id);
                const file = input?.files?.[0];
                const key = docMap[id];
                if (file) {
                    this.docs[key] = {
                        name: file.name,
                        size: this.formatBytes(file.size)
                    };
                }
            });
        },

        goToStep(targetStep) {
            if (targetStep < this.step) {
                this.step = targetStep;
                this.scrollToTop();
                return;
            }

            // Validate intermediate steps before jumping forward
            for (let s = this.step; s < targetStep; s++) {
                if (!this.validateStep(s)) {
                    if (window.AdasiToast) {
                        window.AdasiToast.warning(this.clientStepError || 'Harap lengkapi langkah ini sebelum berpindah.');
                    }
                    return;
                }
            }

            if (targetStep === 4) {
                this.updateDocsSummary();
            }

            this.step = targetStep;
            this.scrollToTop();
        },

        nextStep() {
            if (this.validateStep(this.step)) {
                if (this.step < this.maxStep) {
                    if (this.step === 3) {
                        this.updateDocsSummary();
                    }
                    this.step++;
                    this.scrollToTop();
                }
            } else {
                if (window.AdasiToast) {
                    window.AdasiToast.warning(this.clientStepError || 'Lengkapi kolom yang wajib diisi untuk melanjutkan.');
                }
            }
        },

        prevStep() {
            if (this.step > 1) {
                this.step--;
                this.scrollToTop();
            }
        },

        scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        handleSubmit(event) {
            if (!this.validateStep(1)) {
                event.preventDefault();
                this.goToStep(1);
                if (window.AdasiToast) {
                    window.AdasiToast.error(this.clientStepError || 'Lengkapi data pada Langkah 1 terlebih dahulu.');
                }
                return;
            }

            if (!this.validateStep(2)) {
                event.preventDefault();
                this.goToStep(2);
                if (window.AdasiToast) {
                    window.AdasiToast.error(this.clientStepError || 'Lengkapi data pada Langkah 2 terlebih dahulu.');
                }
                return;
            }

            if (!this.validateStep(3)) {
                event.preventDefault();
                this.goToStep(3);
                if (window.AdasiToast) {
                    window.AdasiToast.error(this.clientStepError || 'Lengkapi dokumen wajib pada Langkah 3.');
                }
                return;
            }

            if (!this.legalDeclaration) {
                event.preventDefault();
                this.clientStepError = 'Anda wajib menyetujui pernyataan kebenaran data sebelum mengirim pendaftaran.';
                if (window.AdasiToast) {
                    window.AdasiToast.warning(this.clientStepError);
                }
                return;
            }

            this.isSubmitting = true;

            // Clear draft storage upon submission dispatch
            try {
                localStorage.removeItem('adasi_supplier_reg_draft');
            } catch (e) {}
        }
    }));
});
</script>
@endsection
