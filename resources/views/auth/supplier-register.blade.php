@extends('layouts.auth')

@section('title', __('registration.title'))

@php
    $currentBank = old('bank_name', '');
    $allStandardBanks = \App\Support\BankList::all();
    $isStandardBank = in_array(strtoupper(trim($currentBank)), array_map('strtoupper', array_map('trim', $allStandardBanks)), true);
    $initialBankSelect = $currentBank === '' ? '' : ($isStandardBank ? $currentBank : 'OTHER');
    $initialOtherBankName = $isStandardBank ? '' : $currentBank;

    $bankOptions = \App\Support\BankList::options($isStandardBank ? $currentBank : null);
    $bankOptions[] = [
        'value' => 'OTHER',
        'label' => __('registration.bank_other'),
        'sublabel' => __('registration.bank_other_help'),
        'badge' => __('registration.manual_entry'),
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

{{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     LEFT PANEL: ACTIVE ONBOARDING COMPANION (Desktop Sticky)
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
@section('brand-panel')
<aside class="auth-brand-panel tw-relative tw-flex tw-flex-col tw-justify-between tw-overflow-y-auto tw-bg-[#0B1528] tw-text-white tw-p-8 lg:tw-p-10 xl:tw-p-12 tw-sticky tw-top-0 tw-h-screen tw-z-10" aria-label="{{ __('registration.guide') }}">
    {{-- Subtle industrial ambient background overlay --}}
    <div class="tw-absolute tw-inset-0 tw-z-0 tw-opacity-15 tw-pointer-events-none">
        <img src="{{ asset('assets/images/adasi-login-bg.jpg') }}" alt="" class="tw-w-full tw-h-full tw-object-cover" draggable="false">
    </div>
    <div class="tw-absolute tw-inset-0 tw-z-0 tw-bg-gradient-to-b tw-from-[#0B1528]/95 tw-via-[#0B1528]/85 tw-to-[#0B1528]/95 tw-pointer-events-none"></div>

    {{-- Brand & Header Section --}}
    <div class="tw-relative tw-z-10">
        <div class="tw-flex tw-items-center tw-gap-3">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="{{ __('common.review.logo') }}" class="tw-h-9 tw-w-auto tw-shrink-0" draggable="false">
            <div>
                <div class="tw-text-ui-xs tw-font-bold tw-tracking-wider tw-text-white/90 tw-uppercase">PT Astra Daido Steel Indonesia</div>
                <div class="tw-text-[11px] tw-font-medium tw-text-white/60">{{ __('registration.portal') }}</div>
            </div>
        </div>

        <div class="tw-mt-8">
            <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-[11px] tw-font-semibold tw-bg-primary/20 tw-text-primary-200 tw-border tw-border-primary/30">
                <x-ui.icon name="shield-check" size="xs" />
                <span>{{ __('registration.official') }}</span>
            </span>
            <h1 class="tw-text-ui-xl xl:tw-text-ui-2xl tw-font-bold tw-text-white tw-mt-2 tw-leading-tight">
                {{ __('registration.join') }}
            </h1>
            <p class="tw-text-ui-xs tw-text-white/70 tw-mt-2 tw-leading-relaxed tw-max-w-md">
                {{ __('registration.join_help') }}
            </p>
        </div>

        {{-- Vertical Stepper Tracker --}}
        <div class="tw-mt-8 tw-space-y-4" role="navigation" aria-label="{{ __('registration.steps') }}">
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
                        {{ __('registration.steps.account') }}
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">{{ __('registration.step1_help') }}</div>
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
                        {{ __('registration.steps.legal') }}
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">{{ __('registration.step2_help') }}</div>
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
                        {{ __('registration.steps.documents') }}
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">{{ __('registration.step3_help') }}</div>
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
                        {{ __('registration.steps.review') }}
                    </div>
                    <div class="tw-text-[11px] tw-text-white/50 tw-mt-0.5">{{ __('registration.step4_help') }}</div>
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
                    <span>{{ __('registration.guide_credentials') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                {{ __('registration.guide_email') }}
            </p>
            </div>

            {{-- Step 2 Tip --}}
            <div x-show="step === 2" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="credit-card" size="xs" />
                    <span>{{ __('registration.guide_bank') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                {{ __('registration.guide_nib') }}
            </p>
            </div>

            {{-- Step 3 Tip --}}
            <div x-show="step === 3" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="file-text" size="xs" />
                    <span>{{ __('registration.guide_documents') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                {{ __('registration.guide_sknr') }}
            </p>
            </div>

            {{-- Step 4 Tip --}}
            <div x-show="step === 4" x-cloak class="tw-transition-opacity tw-duration-200">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary-200 tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="shield-check" size="xs" />
                    <span>{{ __('registration.guide_review') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-white/75 tw-mt-1.5 tw-mb-0 tw-leading-relaxed">
                {{ __('registration.guide_after') }}
            </p>
            </div>
        </div>
    </div>

    {{-- Bottom Support Card --}}
    <div class="tw-relative tw-z-10 tw-pt-4 tw-border-t tw-border-white/10 tw-text-ui-xs tw-text-white/60">
        <div class="tw-flex tw-items-center tw-justify-between">
            <div>
                <div class="tw-text-[11px] tw-font-medium tw-text-white/40">{{ __('registration.support') }}</div>
                <div class="tw-text-white/90 tw-font-semibold tw-mt-0.5">procurement@astra-daido.co.id</div>
            </div>
            <a href="{{ route('login') }}" class="tw-text-primary-300 hover:tw-text-primary-200 tw-text-ui-xs tw-font-semibold tw-underline">
                {{ __('registration.portal_sign_in') }}
            </a>
        </div>
        <div class="tw-mt-3 tw-text-[10px] tw-text-white/40">
            &copy; {{ now()->year }} PT Astra Daido Steel Indonesia. {{ __('registration.rights') }}
        </div>
    </div>
</aside>
@endsection

{{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     RIGHT PANEL: PROGRESSIVE WIZARD FORM
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
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
    .auth-form-panel {
        align-items: stretch !important;
        justify-content: flex-start !important;
        padding: 1.5rem 1rem !important;
    }
    @media (min-width: 640px) {
        .auth-form-panel {
            padding: 2rem 1.5rem !important;
        }
    }
    .auth-form-surface { max-width: 54rem !important; width: 100% !important; margin: 0 auto !important; }
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
            {{ __('registration.step') }} <span x-text="step"></span> {{ __('registration.of_four') }}
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
        <div class="tw-flex tw-items-center tw-gap-2.5">
            <div class="form-step-badge">
                <x-ui.icon name="layers" size="xs" />
                <span>{{ __('registration.step') }} <span x-text="step"></span> / 4</span>
            </div>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-hidden sm:tw-inline">
                {{ __('registration.fields_marked') }} <span class="tw-text-error tw-font-bold">*</span> {{ __('registration.required_text') }}
            </span>
        </div>

        {{-- Language Switcher Toggle --}}
        <div class="tw-inline-flex tw-items-center tw-p-1 tw-rounded-ui-full tw-bg-surface-container-high tw-border tw-border-outline-variant/60 tw-shadow-xs" role="group" aria-label="{{ __('registration.language_selector') }}">
            <button
                type="button"
                @click="switchLanguage('id')"
                class="ui-motion ui-focus-ring tw-flex tw-items-center tw-gap-1.5 tw-px-3 tw-py-1 tw-rounded-ui-full tw-text-ui-xs tw-font-bold tw-transition-all"
                :class="currentLocale === 'id' ? 'tw-bg-surface tw-text-primary tw-shadow-sm tw-scale-100' : 'tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface/50'"
                aria-label="Bahasa Indonesia"
            >
                <span class="tw-text-xs">ðŸ‡®ðŸ‡©</span>
                <span>ID</span>
            </button>
            <button
                type="button"
                @click="switchLanguage('en')"
                class="ui-motion ui-focus-ring tw-flex tw-items-center tw-gap-1.5 tw-px-3 tw-py-1 tw-rounded-ui-full tw-text-ui-xs tw-font-bold tw-transition-all"
                :class="currentLocale === 'en' ? 'tw-bg-surface tw-text-primary tw-shadow-sm tw-scale-100' : 'tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface/50'"
                aria-label="English"
            >
                <span class="tw-text-xs">ðŸ‡¬ðŸ‡§</span>
                <span>EN</span>
            </button>
        </div>
    </div>

    <h2 class="tw-m-0 tw-mt-2 tw-text-ui-xl lg:tw-text-ui-2xl tw-font-bold tw-tracking-tight tw-text-on-surface" x-text="stepHeadings[step]">
        {{ __('registration.account_profile') }}
    </h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant" x-text="stepSubheadings[step]">
        {{ __('registration.start_help') }}
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
            <div class="tw-text-ui-xs tw-font-bold tw-text-on-surface">{{ __('registration.draft') }}</div>
            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                {{ __('registration.draft_help') }}
            </div>
        </div>
    </div>
    <div class="tw-flex tw-items-center tw-gap-2 tw-shrink-0">
        <button
            type="button"
            class="ui-motion ui-focus-ring tw-px-3 tw-py-1.5 tw-rounded-ui-xs tw-bg-primary tw-text-white tw-text-ui-xs tw-font-semibold hover:tw-brightness-95 active:tw-scale-95"
            @click="restoreDraft()"
        >
            {{ __('registration.restore') }}
        </button>
        <button
            type="button"
            class="ui-motion ui-focus-ring tw-px-2.5 tw-py-1.5 tw-rounded-ui-xs tw-border tw-border-outline-variant tw-bg-surface tw-text-on-surface-variant tw-text-ui-xs hover:tw-bg-surface-container"
            @click="discardDraft()"
        >
            {{ __('registration.ignore') }}
        </button>
    </div>
</div>

{{-- Server-Side Error Alert --}}
@if ($errors->any())
    <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3.5 tw-text-on-error-container tw-mb-5" role="alert">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold tw-text-ui-sm tw-mb-1">
            <x-ui.icon name="alert-triangle" size="sm" />
            <span>{{ __('registration.errors') }}</span>
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

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 1: ACCOUNT CREDENTIALS & COMPANY PROFILE
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 1" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 1.1: Portal Account Credentials --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="key" size="sm" />
                <span>{{ __('registration.credentials') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.credentials_help') }}
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                {{-- Official Company Email --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                    <label for="email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.email') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="mail" size="sm" />
                        </div>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            x-model="formData.email"
                            @blur="validateField('email')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.email ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.email_example') }}"
                            required
                        >
                    </div>
                    <p x-show="errors.email" x-text="errors.email" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Password with dynamic validation checklist --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.password') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" />
                        </div>
                        <input
                            id="password"
                            :type="showPass ? 'text' : 'password'"
                            name="password"
                            autocomplete="new-password"
                            x-model="formData.password"
                            @input="validateField('password'); if (formData.password_confirmation) validateField('password_confirmation');"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.password ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.password_min') }}"
                            required
                        >
                        <button
                            type="button"
                            class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                            @click="showPass = !showPass"
                            tabindex="-1"
                            :aria-label="showPass ? @js(__('auth.login.hide_password')) : @js(__('auth.login.show_password'))"
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
                            <span>{{ __('registration.password_min_short') }}</span>
                        </span>
                        <span class="tw-flex tw-items-center tw-gap-1" :class="passwordHasLetter ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'">
                            <x-ui.icon name="check-circle" size="xs" x-show="passwordHasLetter" />
                            <x-ui.icon name="circle" size="xs" x-show="!passwordHasLetter" />
                            <span>{{ __('registration.password_letters') }}</span>
                        </span>
                        <span class="tw-flex tw-items-center tw-gap-1" :class="passwordHasNumber ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'">
                            <x-ui.icon name="check-circle" size="xs" x-show="passwordHasNumber" />
                            <x-ui.icon name="circle" size="xs" x-show="!passwordHasNumber" />
                            <span>{{ __('registration.password_numbers') }}</span>
                        </span>
                    </div>

                    <p x-show="errors.password" x-text="errors.password" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('password')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Password Confirmation --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="password_confirmation" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.password_confirm') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" />
                        </div>
                        <input
                            id="password_confirmation"
                            :type="showConfirmPass ? 'text' : 'password'"
                            name="password_confirmation"
                            autocomplete="new-password"
                            x-model="formData.password_confirmation"
                            @input="validateField('password_confirmation')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.password_confirmation ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.repeat_password') }}"
                            required
                        >
                        <button
                            type="button"
                            class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                            @click="showConfirmPass = !showConfirmPass"
                            tabindex="-1"
                            :aria-label="showConfirmPass ? @js(__('auth.login.hide_password')) : @js(__('auth.login.show_password'))"
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
                <span>{{ __('registration.legal_profile') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.legal_help') }}
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-3">
                {{-- Entity Legal Form --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <label for="company_title_select" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.legal_form') }} <span class="tw-text-error">*</span>
                    </label>
                    <select
                        id="company_title_select"
                        x-model="formData.company_title"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                    >
                        <option value="PT">{{ __('registration.legal.pt') }}</option>
                        <option value="CV">{{ __('registration.legal.cv') }}</option>
                        <option value="UD">{{ __('registration.legal.ud') }}</option>
                        <option value="PD">{{ __('registration.legal.pd') }}</option>
                        <option value="VPD">VPD</option>
                        <option value="Firma">{{ __('registration.legal.firma') }}</option>
                        <option value="Koperasi">{{ __('registration.legal.koperasi') }}</option>
                        <option value="Yayasan">{{ __('registration.legal.yayasan') }}</option>
                        <option value="Company">{{ __('registration.legal.company') }}</option>
                        <option value="Other">{{ __('registration.legal.other') }}</option>
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
                        {{ __('registration.special_legal_form') }} <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="custom_company_title"
                        type="text"
                        name="custom_company_title"
                        x-model="formData.custom_company_title"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        placeholder="{{ __('registration.legal_example') }}"
                    >
                </div>

                {{-- Registered Company Name --}}
                <div class="tw-grid tw-gap-1.5" :class="formData.company_title === 'Other' ? 'md:tw-col-span-3' : 'md:tw-col-span-2'">
                    <label for="company_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.company') }} <span class="tw-text-error">*</span>
                    </label>
                    <input
                        id="company_name"
                        type="text"
                        name="company_name"
                        autocomplete="organization"
                        x-model="formData.company_name"
                        @blur="validateField('company_name')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.company_name ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="{{ __('registration.company_example') }}"
                        required
                    >
                    <p x-show="errors.company_name" x-text="errors.company_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('company_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Official Company Address --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-3">
                    <label for="address" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.address') }} <span class="tw-text-error">*</span>
                    </label>
                    <textarea
                        id="address"
                        name="address"
                        autocomplete="street-address"
                        rows="3"
                        x-model="formData.address"
                        @blur="validateField('address')"
                        class="ui-motion tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-p-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        :class="errors.address ? 'tw-border-error' : 'tw-border-outline-variant'"
                        placeholder="{{ __('registration.address_example') }}"
                        required
                    ></textarea>
                    <p x-show="errors.address" x-text="errors.address" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('address')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Company Phone / Telephone --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                    <label for="phone" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.company_phone') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="phone" size="sm" />
                        </div>
                        <input
                            id="phone"
                            type="text"
                            name="phone"
                            autocomplete="tel"
                            x-model="formData.phone"
                            @blur="validateField('phone')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.phone ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.phone_example') }}"
                            required
                        >
                    </div>
                    <p x-show="errors.phone" x-text="errors.phone" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Business Category / Sector --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-1">
                    <label for="category" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.business_sector') }}
                    </label>
                    <input
                        id="category"
                        type="text"
                        name="category"
                        x-model="formData.category"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        placeholder="{{ __('registration.sector_example') }}"
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
                                {{ __('registration.pkp') }}
                            </span>
                            <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                {{ __('registration.pkp_help') }}
                            </p>
                        </div>
                    </label>
                </div>
            </div>
        </div>
    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 2: LEGAL & TAX IDENTIFICATION, PIC & OFFICIAL BANK
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 2" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 2.1: Legal & Tax Identification --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="file-badge" size="sm" />
                <span>{{ __('registration.legal_identification') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.legal_identifiers_help') }}
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                {{-- NIB Input with live digit counter --}}
                <div class="tw-grid tw-gap-1.5">
                    <div class="tw-flex tw-items-center tw-justify-between">
                        <label for="nib" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                            {{ __('registration.nib') }} <span class="tw-text-error">*</span>
                        </label>
                        <span
                            class="tw-text-[11px] tw-font-mono tw-font-semibold tw-tabular-nums"
                            :class="formData.nib.replace(/\D/g, '').length === 13 ? 'tw-text-success' : 'tw-text-on-surface-variant'"
                            x-text="window.AdasiI18n.t('js.validation.digit_progress', { count: formData.nib.replace(/\D/g, '').length, max: 13 })"
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
                            placeholder="{{ __('registration.nib_number') }}"
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
                            {{ __('registration.tax_number') }} <span class="tw-text-error">*</span>
                        </label>
                        <span
                            class="tw-text-[11px] tw-font-mono tw-font-semibold tw-tabular-nums"
                            :class="[15, 16].includes(formData.npwp.replace(/\D/g, '').length) ? 'tw-text-success' : 'tw-text-on-surface-variant'"
                            x-text="window.AdasiI18n.t('js.validation.digit_progress', { count: formData.npwp.replace(/\D/g, '').length, max: 16 })"
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
                            placeholder="{{ __('registration.npwp_number') }}"
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
                <span>{{ __('registration.contact_section') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.contact_help') }}
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                {{-- PIC Full Name (Span 2 / Full Width) --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                    <label for="pic_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.pic') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="user" size="sm" />
                        </div>
                        <input
                            id="pic_name"
                            type="text"
                            name="pic_name"
                            autocomplete="name"
                            x-model="formData.pic_name"
                            @blur="validateField('pic_name')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.pic_name ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.pic_name_example') }}"
                            required
                        >
                    </div>
                    <p x-show="errors.pic_name" x-text="errors.pic_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('pic_name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- PIC Email Address --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="pic_email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.pic_email') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="mail" size="sm" />
                        </div>
                        <input
                            id="pic_email"
                            type="email"
                            name="pic_email"
                            autocomplete="email"
                            x-model="formData.pic_email"
                            @blur="validateField('pic_email')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.pic_email ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="pic@perusahaan.co.id"
                            required
                        >
                    </div>
                    <p x-show="errors.pic_email" x-text="errors.pic_email" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('pic_email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- PIC Phone / WhatsApp --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="pic_phone" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.pic_phone') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="phone" size="sm" />
                        </div>
                        <input
                            id="pic_phone"
                            type="text"
                            name="pic_phone"
                            autocomplete="tel"
                            x-model="formData.pic_phone"
                            @blur="validateField('pic_phone')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.pic_phone ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="08xxxxxxxxxx"
                            required
                        >
                    </div>
                    <p x-show="errors.pic_phone" x-text="errors.pic_phone" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('pic_phone')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 2.3: Official Bank Account --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="credit-card" size="sm" />
                <span>{{ __('registration.bank_section') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.bank_help') }}
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                <input type="hidden" name="bank_name" :value="finalBankName">

                {{-- Bank Select --}}
                <div class="tw-grid tw-gap-1.5">
                    <x-ui.searchable-select
                        name="bank_select"
                        id="bank_select"
                        :label="__('registration.bank')"
                        :placeholder="__('registration.choose_bank')"
                        search-placeholder="{{ __('registration.search_bank') }}"
                        :options="$bankOptions"
                        :value="old('bank_select', $initialBankSelect)"
                        :error="$errors->first('bank_name')"
                        required
                        x-on:change="onBankChange($event)"
                    />
                    <p x-show="errors.bank_name" x-text="errors.bank_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                </div>

                {{-- Account Number --}}
                <div class="tw-grid tw-gap-1.5">
                    <label for="account_number" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.bank_number') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="credit-card" size="sm" />
                        </div>
                        <input
                            id="account_number"
                            type="text"
                            name="account_number"
                            x-model="formData.account_number"
                            @blur="validateField('account_number')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-font-mono tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.account_number ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.account_number_help') }}"
                            required
                        >
                    </div>
                    <p x-show="errors.account_number" x-text="errors.account_number" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('account_number')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Beneficiary Name (Span 2 / Full Width) --}}
                <div class="tw-grid tw-gap-1.5 md:tw-col-span-2">
                    <label for="account_holder_name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">
                        {{ __('registration.account_holder') }} <span class="tw-text-error">*</span>
                    </label>
                    <div class="tw-relative">
                        <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                            <x-ui.icon name="building-2" size="sm" />
                        </div>
                        <input
                            id="account_holder_name"
                            type="text"
                            name="account_holder_name"
                            x-model="formData.account_holder_name"
                            @blur="validateField('account_holder_name')"
                            class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                            :class="errors.account_holder_name ? 'tw-border-error' : 'tw-border-outline-variant'"
                            placeholder="{{ __('registration.account_holder_help') }}"
                            required
                        >
                    </div>
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
                    class="md:tw-col-span-2 tw-grid tw-gap-1.5 tw-p-3.5 tw-rounded-ui-sm tw-border tw-border-primary/25 tw-bg-primary/5"
                >
                    <label for="other_bank_name" class="tw-text-ui-xs tw-font-semibold tw-text-primary tw-flex tw-items-center tw-gap-1.5">
                        <x-ui.icon name="landmark" size="xs" />
                        <span>{{ __('registration.other_bank') }} <span class="tw-text-error">*</span></span>
                    </label>
                    <input
                        id="other_bank_name"
                        name="other_bank_name"
                        x-ref="otherBankInput"
                        type="text"
                        x-model="formData.other_bank_name"
                        @input="validateField('bank_name')"
                        class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary/20"
                        placeholder="{{ __('registration.bank_example') }}"
                        :required="formData.bank_select === 'OTHER'"
                    >
                    <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant">
                        {{ __('registration.other_bank_help') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 3: VERIFICATION DOCUMENTS (DRAG & DROP ZONES)
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 3" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="file-text" size="sm" />
                    <span>{{ __('registration.document_section') }}</span>
                </div>
                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                    <x-ui.icon name="lock" size="xs" />
                    <span>{{ __('registration.private_storage') }}</span>
                </span>
            </div>
            <p class="tw-m-0 tw-mb-5 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.document_help') }}
            </p>

            {{-- 3.1 Mandatory Documents --}}
            <div class="tw-mb-5">
                <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3">
                    <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-bold tw-bg-error/10 tw-text-error">
                        {{ __('registration.required_documents') }}
                    </span>
                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ __('registration.required_documents_help') }}</span>
                </div>

                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                    {{-- NIB File Dropzone --}}
                    <x-ui.file-upload
                        name="nib_file"
                        id="nib_file"
                        :label="__('registration.nib_document')"
                        :helper="__('registration.nib_help')"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="true"
                    />

                    {{-- NPWP File Dropzone --}}
                    <x-ui.file-upload
                        name="npwp_file"
                        id="npwp_file"
                        :label="__('registration.npwp_document')"
                        :helper="__('registration.npwp_help')"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="true"
                    />

                    {{-- SKNR File Dropzone (Full Width Featured) --}}
                    <div class="md:tw-col-span-2">
                        <x-ui.file-upload
                            name="sknr_file"
                            id="sknr_file"
                            :label="__('registration.sknr_document')"
                            :helper="__('registration.sknr_help')"
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
                        {{ __('registration.optional_documents') }}
                    </span>
                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ __('registration.optional_help') }}</span>
                </div>

                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                    {{-- SPPKP File Dropzone --}}
                    <x-ui.file-upload
                        name="sppkp_file"
                        id="sppkp_file"
                        :label="__('registration.sppkp')"
                        :helper="__('registration.sppkp_help')"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="false"
                    />

                    {{-- SKD File Dropzone --}}
                    <x-ui.file-upload
                        name="skd_file"
                        id="skd_file"
                        :label="__('registration.skd')"
                        :helper="__('registration.skd_help')"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :max-size-mb="5"
                        :required="false"
                    />
                </div>
            </div>
        </div>
    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 4: PRE-FLIGHT REVIEW & CONFIRMATION
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 4" x-cloak class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 4 Header --}}
        <div class="tw-p-4 tw-rounded-ui-md tw-bg-primary/10 tw-border tw-border-primary/20 tw-flex tw-items-start tw-gap-3">
            <x-ui.icon name="check-circle" size="md" class="tw-text-primary tw-shrink-0 tw-mt-0.5" />
            <div>
                <div class="tw-text-ui-sm tw-font-bold tw-text-primary">{{ __('registration.review_heading') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                    {{ __('registration.review_help') }}
                </div>
            </div>
        </div>

        {{-- Summary Card 1: Akun & Profil Perusahaan --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="building-2" size="sm" class="tw-text-primary" />
                    <span>{{ __('registration.company_account') }}</span>
                </div>
                <button
                    type="button"
                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-primary/10"
                    @click="goToStep(1)"
                >
                    <x-ui.icon name="pencil" size="xs" />
                    <span>{{ __('registration.edit_data') }}</span>
                </button>
            </div>

            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 tw-text-ui-xs">
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.company') }}</span>
                    <span class="tw-font-bold tw-text-on-surface" x-text="(formData.company_title === 'Other' ? formData.custom_company_title : formData.company_title) + ' ' + formData.company_name"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.email') }}</span>
                    <span class="tw-font-mono tw-font-semibold tw-text-on-surface" x-text="formData.email"></span>
                </div>
                <div class="sm:tw-col-span-2">
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.address') }}</span>
                    <span class="tw-text-on-surface" x-text="formData.address"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.phone') }}</span>
                    <span class="tw-font-mono tw-text-on-surface" x-text="formData.phone"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.sector') }}</span>
                    <span class="tw-text-on-surface" x-text="(formData.category || summaryLabels.categoryGeneral) + ' Â· ' + (formData.is_pkp ? summaryLabels.pkp : summaryLabels.nonPkp)"></span>
                </div>
            </div>
        </div>

        {{-- Summary Card 2: Legalitas, PIC & Rekening Bank --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="credit-card" size="sm" class="tw-text-primary" />
                    <span>{{ __('registration.legal_pic_bank') }}</span>
                </div>
                <button
                    type="button"
                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-primary/10"
                    @click="goToStep(2)"
                >
                    <x-ui.icon name="pencil" size="xs" />
                    <span>{{ __('registration.edit_data') }}</span>
                </button>
            </div>

            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3 tw-text-ui-xs">
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.nib') }}</span>
                    <span class="tw-font-mono tw-font-bold tw-text-on-surface" x-text="formData.nib"></span>
                </div>
                <div class="sm:tw-col-span-2">
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.npwp') }}</span>
                    <span class="tw-font-mono tw-font-bold tw-text-on-surface" x-text="formData.npwp"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.pic') }}</span>
                    <span class="tw-font-semibold tw-text-on-surface" x-text="formData.pic_name"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.pic_email') }}</span>
                    <span class="tw-font-mono tw-text-on-surface" x-text="formData.pic_email"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.pic_phone') }}</span>
                    <span class="tw-font-mono tw-text-on-surface" x-text="formData.pic_phone"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.bank') }}</span>
                    <span class="tw-font-semibold tw-text-on-surface" x-text="finalBankName"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.account') }}</span>
                    <span class="tw-font-mono tw-font-bold tw-text-on-surface" x-text="formData.account_number"></span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant tw-block">{{ __('registration.summary.holder') }}</span>
                    <span class="tw-font-semibold tw-text-on-surface" x-text="formData.account_holder_name"></span>
                </div>
            </div>
        </div>

        {{-- Summary Card 3: Dokumen Verifikasi --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="file-text" size="sm" class="tw-text-primary" />
                    <span>{{ __('registration.attached_documents') }}</span>
                </div>
                <button
                    type="button"
                    class="ui-motion ui-focus-ring tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-1 tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-bg-primary/10"
                    @click="goToStep(3)"
                >
                    <x-ui.icon name="pencil" size="xs" />
                    <span>{{ __('registration.edit_documents') }}</span>
                </button>
            </div>

            <div class="tw-space-y-2 tw-text-ui-xs">
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">{{ __('registration.summary.nib_document') }}</span>
                    <span class="tw-font-mono tw-text-primary" x-text="docs.nib ? (docs.nib.name + ' (' + docs.nib.size + ')') : @js(__('registration.js.ready'))"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">{{ __('registration.summary.npwp_document') }}</span>
                    <span class="tw-font-mono tw-text-primary" x-text="docs.npwp ? (docs.npwp.name + ' (' + docs.npwp.size + ')') : @js(__('registration.js.ready'))"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">{{ __('registration.summary.sknr_document') }}</span>
                    <span class="tw-font-mono tw-text-primary" x-text="docs.sknr ? (docs.sknr.name + ' (' + docs.sknr.size + ')') : @js(__('registration.js.ready'))"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">{{ __('registration.summary.sppkp_document') }}</span>
                    <span class="tw-text-on-surface-variant" x-text="docs.sppkp ? (docs.sppkp.name + ' (' + docs.sppkp.size + ')') : @js(__('registration.js.not_attached'))"></span>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">{{ __('registration.summary.skd_document') }}</span>
                    <span class="tw-text-on-surface-variant" x-text="docs.skd ? (docs.skd.name + ' (' + docs.skd.size + ')') : @js(__('registration.js.not_attached'))"></span>
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
                    <strong>{{ __('registration.declaration_title') }}</strong>
                    {{ __('registration.declaration') }}
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

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         BOTTOM WIZARD ACTION NAVIGATION BAR
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
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
                <span>{{ __('common.actions.back') }}</span>
            </button>
        </div>

        {{-- Step Indicator Center --}}
        <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">
            {{ __('registration.step') }} <span x-text="step"></span> {{ __('registration.of_four') }}
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
                <span>{{ __('common.actions.continue') }}</span>
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
                        <span>{{ __('registration.submitting') }}</span>
                    </div>
                </template>
                <template x-if="!isSubmitting">
                    <div class="tw-flex tw-items-center tw-gap-2">
                        <x-ui.icon name="send" size="sm" />
                        <span>{{ __('registration.submit') }}</span>
                    </div>
                </template>
            </button>
        </div>
    </div>
</form>

<div class="tw-mt-6 tw-pt-4 tw-border-t tw-border-outline-variant tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
    <p class="tw-m-0">
        {{ __('registration.already_registered') }}
        <a href="{{ route('supplier.registration.access-form') }}" class="tw-font-semibold tw-text-primary hover:tw-underline">
            {{ __('registration.check_registration') }}
        </a>
    </p>
    <p class="tw-m-0 tw-mt-1.5">
        <a href="{{ route('login') }}" class="tw-text-on-surface-variant hover:tw-text-primary hover:tw-underline">
            {{ __('registration.back_login') }}
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
        currentLocale: @js(app()->getLocale()),
        lastWarningToast: '',
        lastWarningToastTime: 0,

        stepTitles: {
            1: @js(__('registration.js.step1')),
            2: @js(__('registration.js.step2')),
            3: @js(__('registration.js.step3')),
            4: @js(__('registration.js.step4'))
        },

        stepHeadings: {
            1: @js(__('registration.account_profile')),
            2: @js(__('registration.legal_pic_bank')),
            3: @js(__('registration.js.heading3')),
            4: @js(__('registration.js.heading4'))
        },

        stepSubheadings: {
            1: @js(__('registration.js.help1')),
            2: @js(__('registration.js.help2')),
            3: @js(__('registration.js.help3')),
            4: @js(__('registration.js.help4'))
        },

        summaryLabels: {
            categoryGeneral: @js(__('registration.js.category_general')),
            pkp: @js(__('registration.js.pkp_status')),
            nonPkp: @js(__('registration.js.non_pkp_status')),
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

        showWarningToast(msg) {
            const now = Date.now();
            if (this.lastWarningToast === msg && (now - this.lastWarningToastTime) < 2000) {
                return;
            }
            this.lastWarningToast = msg;
            this.lastWarningToastTime = now;
            if (window.AdasiToast) {
                window.AdasiToast.warning(msg);
            }
        },

        focusField(fieldId) {
            this.$nextTick(() => {
                const el = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (typeof el.focus === 'function') {
                        el.focus({ preventScroll: true });
                    }
                }
            });
        },

        switchLanguage(targetLocale) {
            if (targetLocale === this.currentLocale) return;
            try {
                this.persistDraft();
                sessionStorage.setItem('adasi_supplier_reg_autorestore', '1');
                sessionStorage.setItem('adasi_supplier_reg_step', String(this.step));
            } catch (e) {}
            window.location.href = @js(url('/locale')) + '/' + targetLocale + '?return_to=' + encodeURIComponent(window.location.pathname + window.location.search);
        },

        onBankChange(event) {
            let val = '';
            if (event?.detail !== undefined && event.detail !== null) {
                val = typeof event.detail === 'object' ? (event.detail.value ?? '') : String(event.detail);
            } else if (event?.target?.value !== undefined) {
                val = event.target.value;
            } else {
                val = document.getElementById('bank_select')?.value || '';
            }
            this.formData.bank_select = val;
            if (this.formData.bank_select === 'OTHER') {
                this.$nextTick(() => {
                    this.$refs.otherBankInput?.focus();
                });
            }
            this.validateField('bank_name');
            this.persistDraft();
        },

        init() {
            let autoRestored = false;
            try {
                if (sessionStorage.getItem('adasi_supplier_reg_autorestore') === '1') {
                    sessionStorage.removeItem('adasi_supplier_reg_autorestore');
                    const savedStep = parseInt(sessionStorage.getItem('adasi_supplier_reg_step') || '1', 10);
                    sessionStorage.removeItem('adasi_supplier_reg_step');
                    this.restoreDraft(false);
                    if (savedStep && savedStep >= 1 && savedStep <= this.maxStep) {
                        this.step = savedStep;
                    }
                    autoRestored = true;
                }
            } catch (e) {}

            if (!autoRestored) {
                this.checkStoredDraft();
            }

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

        restoreDraft(showToast = true) {
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
                    if (showToast && window.AdasiToast) {
                        window.AdasiToast.success(@js(__('registration.js.draft_restored')));
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
                    window.AdasiToast.info(@js(__('registration.js.draft_deleted')));
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
                    this.errors.email = @js(__('registration.js.email_required'));
                } else if (!re.test(this.formData.email.trim())) {
                    this.errors.email = @js(__('registration.js.email_invalid'));
                }
            }

            if (field === 'password') {
                if (!this.formData.password) {
                    this.errors.password = @js(__('registration.js.password_required'));
                } else if (this.formData.password.length < 8) {
                    this.errors.password = @js(__('registration.js.password_min'));
                } else if (!this.passwordHasLetter || !this.passwordHasNumber) {
                    this.errors.password = @js(__('registration.js.password_content'));
                }
            }

            if (field === 'password_confirmation') {
                if (!this.formData.password_confirmation) {
                    this.errors.password_confirmation = @js(__('registration.js.password_confirm_required'));
                } else if (this.formData.password !== this.formData.password_confirmation) {
                    this.errors.password_confirmation = @js(__('registration.js.password_confirm_invalid'));
                }
            }

            if (field === 'company_name') {
                if (!this.formData.company_name || !this.formData.company_name.trim()) {
                    this.errors.company_name = @js(__('registration.js.company_required'));
                }
            }

            if (field === 'address') {
                if (!this.formData.address || !this.formData.address.trim()) {
                    this.errors.address = @js(__('registration.js.address_required'));
                }
            }

            if (field === 'phone') {
                if (!this.formData.phone || !this.formData.phone.trim()) {
                    this.errors.phone = @js(__('registration.js.phone_required'));
                }
            }

            if (field === 'nib') {
                const digits = this.formData.nib.replace(/\D/g, '');
                if (!digits) {
                    this.errors.nib = @js(__('registration.js.nib_required'));
                } else if (digits.length !== 13) {
                    this.errors.nib = @js(__('registration.js.nib_digits')).replace(':count', digits.length);
                }
            }

            if (field === 'npwp') {
                const digits = this.formData.npwp.replace(/\D/g, '');
                if (!digits) {
                    this.errors.npwp = @js(__('registration.js.npwp_required'));
                } else if (![15, 16].includes(digits.length)) {
                    this.errors.npwp = @js(__('registration.js.npwp_digits')).replace(':count', digits.length);
                }
            }

            if (field === 'pic_name') {
                if (!this.formData.pic_name || !this.formData.pic_name.trim()) {
                    this.errors.pic_name = @js(__('registration.js.pic_required'));
                }
            }

            if (field === 'pic_email') {
                const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!this.formData.pic_email || !this.formData.pic_email.trim()) {
                    this.errors.pic_email = @js(__('registration.js.pic_email_required'));
                } else if (!re.test(this.formData.pic_email.trim())) {
                    this.errors.pic_email = @js(__('registration.js.pic_email_invalid'));
                }
            }

            if (field === 'pic_phone') {
                if (!this.formData.pic_phone || !this.formData.pic_phone.trim()) {
                    this.errors.pic_phone = @js(__('registration.js.pic_phone_required'));
                }
            }

            if (field === 'bank_name') {
                if (!this.formData.bank_select) {
                    const domVal = document.getElementById('bank_select')?.value;
                    if (domVal) {
                        this.formData.bank_select = domVal;
                    }
                }
                if (!this.finalBankName || !this.finalBankName.trim()) {
                    this.errors.bank_name = @js(__('registration.js.bank_required'));
                }
            }

            if (field === 'account_number') {
                if (!this.formData.account_number || !this.formData.account_number.trim()) {
                    this.errors.account_number = @js(__('registration.js.account_required'));
                }
            }

            if (field === 'account_holder_name') {
                if (!this.formData.account_holder_name || !this.formData.account_holder_name.trim()) {
                    this.errors.account_holder_name = @js(__('registration.js.holder_required'));
                }
            }
        },

        validateStep(stepNumber) {
            this.clientStepError = '';

            if (stepNumber === 1) {
                const step1Fields = [
                    { key: 'email', id: 'email' },
                    { key: 'password', id: 'password' },
                    { key: 'password_confirmation', id: 'password_confirmation' },
                    { key: 'company_name', id: 'company_name' },
                    { key: 'address', id: 'address' },
                    { key: 'phone', id: 'phone' }
                ];
                step1Fields.forEach(f => this.validateField(f.key));
                const firstInvalid1 = step1Fields.find(f => this.errors[f.key]);

                if (firstInvalid1) {
                    this.clientStepError = @js(__('registration.js.complete_step'));
                    this.focusField(firstInvalid1.id);
                    return false;
                }
                return true;
            }

            if (stepNumber === 2) {
                if (!this.formData.bank_select) {
                    const domVal = document.getElementById('bank_select')?.value;
                    if (domVal) {
                        this.formData.bank_select = domVal;
                    }
                }

                const step2Fields = [
                    { key: 'nib', id: 'nib' },
                    { key: 'npwp', id: 'npwp' },
                    { key: 'pic_name', id: 'pic_name' },
                    { key: 'pic_email', id: 'pic_email' },
                    { key: 'pic_phone', id: 'pic_phone' },
                    { key: 'bank_name', id: (this.formData.bank_select === 'OTHER' ? 'other_bank_name' : 'bank_select') },
                    { key: 'account_number', id: 'account_number' },
                    { key: 'account_holder_name', id: 'account_holder_name' }
                ];
                step2Fields.forEach(f => this.validateField(f.key));
                const firstInvalid2 = step2Fields.find(f => this.errors[f.key]);

                if (firstInvalid2) {
                    this.clientStepError = @js(__('registration.js.complete_identity'));
                    this.focusField(firstInvalid2.id);
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
                let firstMissingId = null;
                if (!nibInput || !nibInput.files || nibInput.files.length === 0) {
                    missingDocs.push('NIB');
                    if (!firstMissingId) firstMissingId = 'nib_file';
                }
                if (!npwpInput || !npwpInput.files || npwpInput.files.length === 0) {
                    missingDocs.push('NPWP');
                    if (!firstMissingId) firstMissingId = 'npwp_file';
                }
                if (!sknrInput || !sknrInput.files || sknrInput.files.length === 0) {
                    missingDocs.push('SKNR');
                    if (!firstMissingId) firstMissingId = 'sknr_file';
                }

                if (missingDocs.length > 0) {
                    this.clientStepError = @js(__('registration.js.documents_missing')).replace(':documents', missingDocs.join(', '));
                    if (firstMissingId) {
                        this.focusField(firstMissingId);
                    }
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
                    this.showWarningToast(this.clientStepError || @js(__('registration.js.before_move')));
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
                this.showWarningToast(this.clientStepError || @js(__('registration.js.required_continue')));
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
                    window.AdasiToast.error(this.clientStepError || @js(__('registration.js.step1_first')));
                }
                return;
            }

            if (!this.validateStep(2)) {
                event.preventDefault();
                this.goToStep(2);
                if (window.AdasiToast) {
                    window.AdasiToast.error(this.clientStepError || @js(__('registration.js.step2_first')));
                }
                return;
            }

            if (!this.validateStep(3)) {
                event.preventDefault();
                this.goToStep(3);
                if (window.AdasiToast) {
                    window.AdasiToast.error(this.clientStepError || @js(__('registration.js.step3_first')));
                }
                return;
            }

            if (!this.legalDeclaration) {
                event.preventDefault();
                this.clientStepError = @js(__('registration.js.declaration_required'));
                this.showWarningToast(this.clientStepError);
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
