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

    $totalSteps = 5;
    $questionnaireKeys = \App\Support\SupplierComplianceQuestionnaire::keys();
    $questionnaireAnswers = \App\Support\SupplierComplianceQuestionnaire::normalize(old('questionnaire', []));

    // Field => wizard step, used to open the earliest step that has a server-side error.
    $fieldSteps = [
        'questionnaire' => 2,
        'nib' => 3, 'npwp' => 3, 'pic_name' => 3, 'pic_email' => 3, 'pic_phone' => 3,
        'bank_name' => 3, 'bank_select' => 3, 'other_bank_name' => 3, 'account_number' => 3, 'account_holder_name' => 3,
        'nib_file' => 4, 'npwp_file' => 4, 'sknr_file' => 4, 'sppkp_file' => 4, 'skd_file' => 4, 'company_profile_file' => 4,
        'cf-turnstile-response' => 5,
    ];
    $errorSteps = collect($errors->keys())
        ->map(fn ($key) => $fieldSteps[\Illuminate\Support\Str::before($key, '.')] ?? 1)
        ->unique()->sort()->values()->all();
    $initialStep = $errorSteps[0] ?? 1;

    $stepper = [
        1 => ['title' => __('registration.stepper.account'), 'help' => __('registration.step1_help'), 'icon' => 'key'],
        2 => ['title' => __('registration.stepper.questionnaire'), 'help' => __('registration.stepper.questionnaire_help'), 'icon' => 'clipboard-check'],
        3 => ['title' => __('registration.stepper.legal'), 'help' => __('registration.step2_help'), 'icon' => 'file-badge'],
        4 => ['title' => __('registration.stepper.documents'), 'help' => __('registration.step3_help'), 'icon' => 'file-text'],
        5 => ['title' => __('registration.stepper.review'), 'help' => __('registration.step4_help'), 'icon' => 'shield-check'],
    ];
@endphp

@section('shell-attributes')
    x-data="supplierRegistrationWizard({ initialStep: {{ $initialStep }}, errorSteps: @js($errorSteps) })"
@endsection

{{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     LEFT PANEL: ACTIVE ONBOARDING COMPANION (Desktop Sticky)
     â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
@section('brand-panel')
<aside class="auth-brand-panel registration-guide-panel tw-relative tw-flex tw-flex-col tw-justify-between tw-overflow-y-auto tw-border-e tw-border-outline-variant tw-text-on-surface tw-p-8 lg:tw-p-10 xl:tw-p-12 tw-sticky tw-top-0 tw-h-screen tw-z-10" aria-label="{{ __('registration.guide') }}">
    {{-- Brand & Header Section --}}
    <div>
        <div class="tw-flex tw-items-center tw-gap-3">
            <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="{{ __('common.review.logo') }}" class="tw-h-9 tw-w-auto tw-shrink-0" draggable="false">
            <div>
                <div class="tw-text-ui-xs tw-font-bold tw-tracking-wider tw-text-on-surface tw-uppercase">PT Astra Daido Steel Indonesia</div>
                <div class="tw-text-[11px] tw-font-medium tw-text-on-surface-variant">{{ __('registration.portal') }}</div>
            </div>
        </div>

        <div class="tw-mt-8">
            <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">
                <x-ui.icon name="shield-check" size="xs" />
                <span>{{ __('registration.official') }}</span>
            </span>
            <h1 class="tw-m-0 tw-text-ui-xl xl:tw-text-ui-2xl tw-font-bold tw-text-on-surface tw-mt-2 tw-leading-tight tw-text-balance">
                {{ __('registration.join') }}
            </h1>
            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant tw-mt-2 tw-leading-relaxed tw-max-w-md tw-text-pretty">
                {{ __('registration.join_help') }}
            </p>
        </div>

        {{-- Vertical Stepper Tracker --}}
        <nav class="tw-mt-8" aria-label="{{ __('registration.steps') }}">
            <ol class="tw-m-0 tw-list-none tw-p-0">
                @foreach ($stepper as $number => $item)
                    <li class="tw-flex tw-gap-3.5">
                        <div class="tw-flex tw-flex-col tw-items-center tw-shrink-0">
                            <button
                                type="button"
                                class="ui-focus-ring tw-flex tw-h-8 tw-w-8 tw-items-center tw-justify-center tw-rounded-full tw-border tw-text-ui-xs tw-font-bold tw-transition-colors"
                                :class="stepMarkerClass({{ $number }})"
                                @click="goToStep({{ $number }})"
                                :aria-current="step === {{ $number }} ? 'step' : null"
                                aria-label="{{ __('registration.step') }} {{ $number }}: {{ $item['title'] }}"
                            >
                                <template x-if="hasStepError({{ $number }})">
                                    <x-ui.icon name="circle-alert" size="xs" />
                                </template>
                                <template x-if="!hasStepError({{ $number }}) && step > {{ $number }}">
                                    <x-ui.icon name="check" size="xs" />
                                </template>
                                <template x-if="!hasStepError({{ $number }}) && step <= {{ $number }}">
                                    <span class="tw-tabular-nums">{{ $number }}</span>
                                </template>
                            </button>
                            @unless ($loop->last)
                                <span class="tw-my-1 tw-w-px tw-flex-1 tw-min-h-6" :class="step > {{ $number }} ? 'tw-bg-primary' : 'tw-bg-outline-variant'" aria-hidden="true"></span>
                            @endunless
                        </div>
                        <div class="tw-pt-1.5 tw-pb-4 tw-min-w-0">
                            <div class="tw-text-ui-xs tw-font-semibold" :class="step === {{ $number }} ? 'tw-text-primary' : (step > {{ $number }} ? 'tw-text-on-surface' : 'tw-text-on-surface-variant')">
                                {{ $item['title'] }}
                            </div>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5 tw-text-pretty">{{ $item['help'] }}</div>
                            <div x-show="hasStepError({{ $number }})" x-cloak class="tw-mt-1 tw-inline-flex tw-items-center tw-gap-1 tw-text-[11px] tw-font-semibold tw-text-error">
                                <x-ui.icon name="circle-alert" size="xs" />
                                <span>{{ __('registration.stepper.has_errors') }}</span>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>

    {{-- Contextual Guidance Card (Dynamically updates per step) --}}
    <div class="tw-my-6">
        <div class="tw-p-4 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface">
            {{-- Step 1 Tip --}}
            <div x-show="step === 1" x-cloak>
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="info" size="xs" />
                    <span>{{ __('registration.guide_credentials') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1.5 tw-mb-0 tw-leading-relaxed tw-text-pretty">
                    {{ __('registration.guide_email') }}
                </p>
            </div>

            {{-- Step 2 Tip --}}
            <div x-show="step === 2" x-cloak>
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="clipboard-check" size="xs" />
                    <span>{{ __('registration.questionnaire.title') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1.5 tw-mb-0 tw-leading-relaxed tw-text-pretty">
                    {{ __('registration.questionnaire.help') }}
                </p>
            </div>

            {{-- Step 3 Tip --}}
            <div x-show="step === 3" x-cloak>
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="credit-card" size="xs" />
                    <span>{{ __('registration.guide_bank') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1.5 tw-mb-0 tw-leading-relaxed tw-text-pretty">
                    {{ __('registration.guide_nib') }}
                </p>
            </div>

            {{-- Step 4 Tip --}}
            <div x-show="step === 4" x-cloak>
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="file-text" size="xs" />
                    <span>{{ __('registration.guide_documents') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1.5 tw-mb-0 tw-leading-relaxed tw-text-pretty">
                    {{ __('registration.guide_sknr') }}
                </p>
            </div>

            {{-- Step 5 Tip --}}
            <div x-show="step === 5" x-cloak>
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-text-ui-xs tw-font-bold">
                    <x-ui.icon name="shield-check" size="xs" />
                    <span>{{ __('registration.guide_review') }}</span>
                </div>
                <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1.5 tw-mb-0 tw-leading-relaxed tw-text-pretty">
                    {{ __('registration.guide_after') }}
                </p>
            </div>
        </div>
    </div>

    {{-- Bottom Support Card --}}
    <div class="tw-pt-4 tw-border-t tw-border-outline-variant tw-text-ui-xs tw-text-on-surface-variant">
        <div class="tw-flex tw-items-center tw-justify-between tw-gap-3">
            <div class="tw-min-w-0">
                <div class="tw-text-[11px] tw-font-medium">{{ __('registration.support') }}</div>
                <div class="tw-text-on-surface tw-font-semibold tw-mt-0.5 tw-break-all">procurement@astra-daido.co.id</div>
            </div>
            <a href="{{ route('login') }}" class="ui-focus-ring tw-shrink-0 tw-rounded-ui-xs tw-text-primary tw-text-ui-xs tw-font-semibold hover:tw-underline">
                {{ __('registration.portal_sign_in') }}
            </a>
        </div>
        <div class="tw-mt-3 tw-text-[10px]">
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
    /*
     * layouts.auth paints the shared .auth-brand-panel navy from an inline <head> style that loads
     * after the Tailwind bundle, so a utility class cannot win. The registration guide is a flat,
     * light ERP surface; two classes outrank the layout rule regardless of stylesheet order.
     */
    .auth-brand-panel.registration-guide-panel {
        background: var(--md-surface-container-low);
        color: var(--md-on-surface);
    }
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
    @media (min-width: 768px) {
        .reg-tax-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            grid-template-rows: auto auto auto !important;
            column-gap: 1rem !important;
            row-gap: 0.375rem !important;
            align-items: start !important;
        }
        .reg-tax-col {
            display: grid !important;
            grid-row: span 3 !important;
            grid-template-rows: subgrid !important;
            row-gap: 0.375rem !important;
        }
        .reg-tax-header-sync {
            min-height: 48px !important;
            display: flex !important;
            align-items: flex-start !important;
        }
    }
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
    .lang-toggle-track {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem;
        border-radius: var(--md-shape-full);
        background: var(--md-surface-container-high);
        border: 1px solid var(--md-outline-strong);
        box-shadow: 0 1px 2px 0 rgba(15, 23, 42, 0.05);
    }
    .lang-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        min-height: 2rem;
        border-radius: var(--md-shape-full);
        font-size: var(--ui-font-size-xs);
        font-weight: 700;
        border: 1px solid transparent;
        color: var(--md-on-surface-variant);
        background: transparent;
        cursor: pointer;
        outline: none;
        transition-property: background-color, color, border-color, box-shadow, transform;
        transition-duration: 160ms;
        transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
    }
    .lang-pill-btn.is-active {
        background: var(--md-surface);
        color: var(--md-primary);
        border-color: var(--md-outline);
        box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.1), 0 1px 2px -1px rgba(15, 23, 42, 0.08);
        cursor: default;
    }
    .lang-pill-btn:not(.is-active):hover {
        color: var(--md-on-surface);
        background: rgba(255, 255, 255, 0.65);
        border-color: rgba(203, 213, 225, 0.4);
    }
    .lang-pill-btn:active {
        transform: scale(0.96);
    }
    .lang-flag-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 14px;
        border-radius: 2.5px;
        overflow: hidden;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.15);
        flex-shrink: 0;
    }
    @media (prefers-reduced-motion: reduce) {
        .lang-pill-btn {
            transition: none !important;
            transform: none !important;
        }
        .lang-pill-btn:active {
            transform: none !important;
        }
    }
</style>

{{-- Mobile Compact Stepper (Visible only on < 1024px) --}}
<nav class="lg:tw-hidden tw-mb-5 tw-pb-4 tw-border-b tw-border-outline-variant" aria-label="{{ __('registration.steps') }}">
    <ol class="tw-m-0 tw-flex tw-list-none tw-items-center tw-gap-1.5 tw-p-0">
        @foreach ($stepper as $number => $item)
            <li class="tw-flex tw-flex-1 tw-items-center tw-gap-1.5 last:tw-flex-none">
                <button
                    type="button"
                    class="ui-focus-ring tw-flex tw-h-8 tw-w-8 tw-shrink-0 tw-items-center tw-justify-center tw-rounded-full tw-border tw-text-ui-xs tw-font-bold tw-transition-colors"
                    :class="stepMarkerClass({{ $number }})"
                    @click="goToStep({{ $number }})"
                    :aria-current="step === {{ $number }} ? 'step' : null"
                    aria-label="{{ __('registration.step') }} {{ $number }}: {{ $item['title'] }}"
                >
                    <template x-if="hasStepError({{ $number }})">
                        <x-ui.icon name="circle-alert" size="xs" />
                    </template>
                    <template x-if="!hasStepError({{ $number }}) && step > {{ $number }}">
                        <x-ui.icon name="check" size="xs" />
                    </template>
                    <template x-if="!hasStepError({{ $number }}) && step <= {{ $number }}">
                        <span class="tw-tabular-nums">{{ $number }}</span>
                    </template>
                </button>
                @unless ($loop->last)
                    <span class="tw-h-px tw-flex-1" :class="step > {{ $number }} ? 'tw-bg-primary' : 'tw-bg-outline-variant'" aria-hidden="true"></span>
                @endunless
            </li>
        @endforeach
    </ol>
    <div class="tw-mt-2.5 tw-flex tw-items-baseline tw-justify-between tw-gap-3">
        <span class="tw-text-ui-sm tw-font-semibold tw-text-on-surface tw-text-pretty" x-text="stepTitles[step]"></span>
        <span class="tw-shrink-0 tw-text-ui-xs tw-font-medium tw-tabular-nums tw-text-on-surface-variant">
            {{ __('registration.step') }} <span x-text="step"></span> {{ __('registration.of_total', ['total' => $totalSteps]) }}
        </span>
    </div>
</nav>

{{-- Main Form Header --}}
<header class="tw-mb-6">
    <div class="tw-flex tw-items-center tw-justify-between tw-gap-3">
        <div class="tw-flex tw-items-center tw-gap-2.5">
            <div class="form-step-badge">
                <x-ui.icon name="layers" size="xs" />
                <span>{{ __('registration.step') }} <span x-text="step"></span> / {{ $totalSteps }}</span>
            </div>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-hidden sm:tw-inline">
                {{ __('registration.fields_marked') }} <span class="tw-text-error tw-font-bold">*</span> {{ __('registration.required_text') }}
            </span>
        </div>

        {{-- Language Switcher Toggle --}}
        <div class="lang-toggle-track tw-inline-flex tw-items-center tw-p-1 tw-rounded-ui-full tw-bg-surface-container-high tw-border tw-border-outline-strong tw-shadow-xs" role="group" aria-label="{{ __('registration.language_selector') }}">
            <button
                type="button"
                @click="switchLanguage('id')"
                class="lang-pill-btn ui-focus-ring tw-flex tw-items-center tw-gap-1.5 tw-px-3 tw-py-1.5 tw-min-h-8 tw-rounded-ui-full tw-text-ui-xs tw-font-bold"
                :class="currentLocale === 'id' ? 'is-active tw-bg-surface tw-text-primary tw-border-outline tw-shadow-xs' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface/60'"
                aria-label="Bahasa Indonesia"
                :aria-pressed="currentLocale === 'id'"
            >
                <span class="lang-flag-badge" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40" preserveAspectRatio="none" class="tw-w-full tw-h-full" focusable="false">
                        <rect width="60" height="20" fill="#e70011"/>
                        <rect y="20" width="60" height="20" fill="#ffffff"/>
                    </svg>
                </span>
                <span class="tw-tracking-wide">ID</span>
            </button>
            <button
                type="button"
                @click="switchLanguage('en')"
                class="lang-pill-btn ui-focus-ring tw-flex tw-items-center tw-gap-1.5 tw-px-3 tw-py-1.5 tw-min-h-8 tw-rounded-ui-full tw-text-ui-xs tw-font-bold"
                :class="currentLocale === 'en' ? 'is-active tw-bg-surface tw-text-primary tw-border-outline tw-shadow-xs' : 'tw-border-transparent tw-text-on-surface-variant hover:tw-text-on-surface hover:tw-bg-surface/60'"
                aria-label="English"
                :aria-pressed="currentLocale === 'en'"
            >
                <span class="lang-flag-badge" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40" preserveAspectRatio="none" class="tw-w-full tw-h-full" focusable="false">
                        <path fill="#012169" d="M0 0h60v40H0z"/>
                        <path stroke="#ffffff" stroke-width="8" d="M0 0l60 40M60 0L0 40"/>
                        <path stroke="#c8102e" stroke-width="2.67" d="M0 0l27 18M60 0L33 18M60 40L33 22M0 40l27-18"/>
                        <path stroke="#ffffff" stroke-width="13.33" d="M30 0v40M0 20h60"/>
                        <path stroke="#c8102e" stroke-width="8" d="M30 0v40M0 20h60"/>
                    </svg>
                </span>
                <span class="tw-tracking-wide">EN</span>
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
    <div class="tw-rounded-ui-sm tw-border tw-border-error/40 tw-bg-error-container tw-p-3.5 tw-text-on-error-container tw-mb-5" role="alert" tabindex="-1" data-error-summary="supplierRegistrationForm">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold tw-text-ui-sm">
            <x-ui.icon name="alert-triangle" size="sm" />
            <span>{{ __('registration.errors') }}</span>
        </div>
        <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs">{{ __('registration.errors_jump_help') }}</p>
        <ul class="tw-m-0 tw-mt-2 tw-ps-5 tw-text-ui-xs tw-space-y-1">
            @foreach ($errors->messages() as $field => $messages)
                <li>
                    <button type="button" class="ui-focus-ring tw-border-0 tw-bg-transparent tw-p-0 tw-text-start tw-font-medium tw-text-on-error-container tw-underline tw-underline-offset-2" data-error-field="{{ $field }}">
                        {{ $messages[0] }}
                    </button>
                </li>
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
    @adasi:form-errors="onServerErrors($event.detail.errors)"
    @adasi:reveal-field="revealStepFor($event.detail.element)"
    @adasi:form-settled="isSubmitting = false"
    @adasi:form-success="clearStoredDraft()"
    data-async-submit
    data-async-inline-errors="off"
    novalidate
>
    @csrf

    {{-- Async (file-preserving) validation summary is rendered here by async-form-submit.js --}}
    <div data-async-error-summary hidden></div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 1: ACCOUNT CREDENTIALS & COMPANY PROFILE
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 1" x-cloak data-wizard-step="1" class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 1.1: Portal Account Credentials --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="key" size="sm" />
                <span>{{ __('registration.credentials') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.credentials_help') }}
            </p>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 tw-items-start">
                {{-- Official Company Email --}}
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-2">
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
                    <p class="tw-m-0 tw-text-[11px] tw-text-on-surface-variant">{{ __('registration.email_help') }}</p>
                    <p x-show="errors.email" x-text="errors.email" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Password (criteria checklist sits on its own row below so both inputs stay aligned) --}}
                <div class="tw-grid tw-content-start tw-gap-1.5">
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

                    <p x-show="errors.password" x-text="errors.password" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                    @error('password')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                </div>

                {{-- Password Confirmation --}}
                <div class="tw-grid tw-content-start tw-gap-1.5">
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

                {{-- Password criteria checklist (mirrors SupplierRegistrationRequest::registrationPasswordRule) --}}
                <ul class="tw-m-0 tw-flex tw-list-none tw-flex-wrap tw-gap-x-4 tw-gap-y-1 tw-p-0 tw-text-[11px] md:tw-col-span-2" aria-label="{{ __('registration.password') }}">
                    @foreach ([
                        'passwordValidLength' => __('registration.password_min_short'),
                        'passwordHasMixedCase' => __('registration.password_mixed_case'),
                        'passwordHasNumber' => __('registration.password_numbers'),
                        'passwordHasSymbol' => __('registration.password_symbol'),
                    ] as $criterion => $criterionLabel)
                        <li class="tw-flex tw-items-center tw-gap-1" :class="{{ $criterion }} ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'">
                            <x-ui.icon name="check-circle" size="xs" x-show="{{ $criterion }}" />
                            <x-ui.icon name="circle" size="xs" x-show="!{{ $criterion }}" />
                            <span>{{ $criterionLabel }}</span>
                        </li>
                    @endforeach
                </ul>
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

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-3 tw-items-start">
                {{-- Entity Legal Form --}}
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-1">
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
                    class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-2"
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
                <div class="tw-grid tw-content-start tw-gap-1.5" :class="formData.company_title === 'Other' ? 'md:tw-col-span-3' : 'md:tw-col-span-2'">
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
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-3">
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
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-2">
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
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-1">
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
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-3">
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
    {{-- STEP 2: QUALITY & COMPLIANCE QUESTIONNAIRE (answers never block submission) --}}
    <div x-show="step === 2" x-cloak data-wizard-step="2" class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-2 tw-mb-1">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="clipboard-check" size="sm" />
                    <span>{{ __('registration.questionnaire.title') }}</span>
                </div>
                <span class="tw-text-[11px] tw-font-semibold tw-tabular-nums tw-text-on-surface-variant" x-text="questionnaireProgressLabel"></span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant tw-text-pretty">
                {{ __('registration.questionnaire.help') }}
            </p>

            <div class="tw-grid tw-gap-3">
                @foreach ($questionnaireKeys as $index => $questionKey)
                    <fieldset
                        class="tw-m-0 tw-min-w-0 tw-rounded-ui-sm tw-border tw-bg-surface tw-px-4 tw-py-3"
                        :class="errors['questionnaire.{{ $questionKey }}'] ? 'tw-border-error' : 'tw-border-outline-variant'"
                    >
                        <legend class="tw-float-left tw-m-0 tw-w-full tw-p-0 tw-text-ui-sm tw-font-medium tw-text-on-surface tw-text-pretty">
                            <span class="tw-tabular-nums tw-text-on-surface-variant">{{ $index + 1 }}.</span>
                            {{ __('registration.questionnaire.questions.'.$questionKey) }}
                            <span class="tw-text-error">*</span>
                        </legend>
                        <div class="tw-clear-both tw-mt-2 tw-flex tw-flex-wrap tw-gap-2">
                            @foreach ([\App\Support\SupplierComplianceQuestionnaire::YES => __('registration.questionnaire.yes'), \App\Support\SupplierComplianceQuestionnaire::NO => __('registration.questionnaire.no')] as $answerValue => $answerLabel)
                                <label
                                    class="tw-inline-flex tw-min-h-10 tw-cursor-pointer tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-px-3.5 tw-text-ui-sm tw-transition-colors"
                                    :class="formData.questionnaire['{{ $questionKey }}'] === '{{ $answerValue }}' ? 'tw-border-primary tw-bg-primary/5 tw-font-semibold tw-text-on-surface' : 'tw-border-outline-variant tw-text-on-surface hover:tw-bg-surface-container'"
                                >
                                    <input
                                        type="radio"
                                        name="questionnaire[{{ $questionKey }}]"
                                        value="{{ $answerValue }}"
                                        x-model="formData.questionnaire['{{ $questionKey }}']"
                                        @change="validateField('questionnaire.{{ $questionKey }}')"
                                        class="form-check-input tw-m-0 tw-h-4 tw-w-4"
                                    >
                                    <span>{{ $answerLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p x-show="errors['questionnaire.{{ $questionKey }}']" x-text="errors['questionnaire.{{ $questionKey }}']" class="tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                        @error('questionnaire.'.$questionKey)<p class="tw-m-0 tw-mt-1.5 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                    </fieldset>
                @endforeach
            </div>
        </div>
    </div>

    {{-- STEP 3: LEGAL & TAX IDENTIFICATION, PIC & OFFICIAL BANK --}}
    <div x-show="step === 3" x-cloak data-wizard-step="3" class="tw-space-y-5 tw-transition-opacity tw-duration-200">
        {{-- Section 3.1: Legal & Tax Identification --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-gap-2 tw-text-primary tw-font-bold tw-text-ui-sm tw-mb-1">
                <x-ui.icon name="file-badge" size="sm" />
                <span>{{ __('registration.legal_identification') }}</span>
            </div>
            <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
                {{ __('registration.legal_identifiers_help') }}
            </p>

            <div class="reg-tax-grid tw-grid tw-gap-4 md:tw-grid-cols-2 tw-items-start">
                {{-- NIB Input with live digit counter --}}
                <div class="reg-tax-col tw-grid tw-content-start tw-gap-1.5">
                    <div class="tw-flex tw-items-start tw-justify-between tw-gap-2 reg-tax-header-sync" style="min-height: 48px;">
                        <label for="nib" class="tw-text-ui-sm tw-font-medium tw-text-on-surface" style="text-wrap: balance;">
                            {{ __('registration.nib') }} <span class="tw-text-error">*</span>
                        </label>
                        <span
                            class="tw-shrink-0 tw-mt-0.5 tw-text-[11px] tw-font-mono tw-font-semibold tw-tabular-nums"
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
                    <div class="tw-min-h-0">
                        <p x-show="errors.nib" x-text="errors.nib" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                        @error('nib')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- NPWP Input with live digit counter --}}
                <div class="reg-tax-col tw-grid tw-content-start tw-gap-1.5">
                    <div class="tw-flex tw-items-start tw-justify-between tw-gap-2 reg-tax-header-sync" style="min-height: 48px;">
                        <label for="npwp" class="tw-text-ui-sm tw-font-medium tw-text-on-surface" style="text-wrap: balance;">
                            {{ __('registration.tax_number') }} <span class="tw-text-error">*</span>
                        </label>
                        <span
                            class="tw-shrink-0 tw-mt-0.5 tw-text-[11px] tw-font-mono tw-font-semibold tw-tabular-nums"
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
                    <div class="tw-min-h-0">
                        <p x-show="errors.npwp" x-text="errors.npwp" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                        @error('npwp')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
                    </div>
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

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 tw-items-start">
                {{-- PIC Full Name (Span 2 / Full Width) --}}
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-2">
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
                <div class="tw-grid tw-content-start tw-gap-1.5">
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
                <div class="tw-grid tw-content-start tw-gap-1.5">
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

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 tw-items-start">
                {{-- Bank Select --}}
                <div class="tw-grid tw-content-start tw-gap-1.5">
                    <input type="hidden" name="bank_name" :value="finalBankName">
                    <x-ui.searchable-select
                        name="bank_select"
                        id="bank_select"
                        :label="__('registration.bank')"
                        :placeholder="__('registration.choose_bank')"
                        search-placeholder="{{ __('registration.search_bank') }}"
                        :options="$bankOptions"
                        :value="old('bank_select', $initialBankSelect)"
                        :error="$errors->first('bank_name')"
                        :show-sublabel-on-trigger="false"
                        required
                        x-on:change="onBankChange($event)"
                    />
                    <p x-show="errors.bank_name" x-text="errors.bank_name" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error"></p>
                </div>

                {{-- Account Number --}}
                <div class="tw-grid tw-content-start tw-gap-1.5">
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

                {{-- Beneficiary Name (Span 2 / Full Width) --}}
                <div class="tw-grid tw-content-start tw-gap-1.5 md:tw-col-span-2">
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
            </div>
        </div>
    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 3: VERIFICATION DOCUMENTS (DRAG & DROP ZONES)
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 4" x-cloak data-wizard-step="4" class="tw-space-y-5 tw-transition-opacity tw-duration-200">
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

                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 tw-items-start">
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

                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2 tw-items-start">
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

                    {{-- Company Profile File Dropzone (optional, larger limit for brochures) --}}
                    <div class="md:tw-col-span-2">
                        <x-ui.file-upload
                            name="company_profile_file"
                            id="company_profile_file"
                            :label="__('registration.company_profile')"
                            :helper="__('registration.company_profile_help')"
                            accept=".pdf,.jpg,.jpeg,.png"
                            :max-size-mb="10"
                            :required="false"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         STEP 4: PRE-FLIGHT REVIEW & CONFIRMATION
         â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div x-show="step === 5" x-cloak data-wizard-step="5" class="tw-space-y-5 tw-transition-opacity tw-duration-200">
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

        {{-- Summary Card: Kuesioner Kualitas & Kepatuhan --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-lowest tw-p-5">
            <div class="tw-flex tw-items-center tw-justify-between tw-mb-3">
                <div class="tw-flex tw-items-center tw-gap-2 tw-text-on-surface tw-font-bold tw-text-ui-sm">
                    <x-ui.icon name="clipboard-check" size="sm" class="tw-text-primary" />
                    <span>{{ __('registration.questionnaire.summary') }}</span>
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

            <dl class="tw-m-0 tw-grid tw-gap-1.5 tw-text-ui-xs">
                @foreach ($questionnaireKeys as $index => $questionKey)
                    <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-rounded tw-bg-surface tw-p-2">
                        <dt class="tw-text-on-surface tw-text-pretty">{{ $index + 1 }}. {{ __('registration.questionnaire.questions.'.$questionKey) }}</dt>
                        <dd class="tw-m-0 tw-shrink-0 tw-font-semibold tw-text-on-surface" x-text="answerLabel('{{ $questionKey }}')"></dd>
                    </div>
                @endforeach
            </dl>
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
                    @click="goToStep(3)"
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
                    @click="goToStep(4)"
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
                <div class="tw-flex tw-items-center tw-justify-between tw-p-2 tw-rounded tw-bg-surface">
                    <span class="tw-font-medium tw-text-on-surface">{{ __('registration.summary.company_profile_document') }}</span>
                    <span class="tw-text-on-surface-variant" x-text="docs.company_profile ? (docs.company_profile.name + ' (' + docs.company_profile.size + ')') : @js(__('registration.js.not_attached'))"></span>
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
        <div class="tw-text-ui-xs tw-font-semibold tw-tabular-nums tw-text-on-surface-variant">
            {{ __('registration.step') }} <span x-text="step"></span> {{ __('registration.of_total', ['total' => $totalSteps]) }}
        </div>

        {{-- Next or Submit Button --}}
        <div>
            {{-- Next Step Button --}}
            <button
                type="button"
                x-show="step < maxStep"
                @click="nextStep()"
                class="ui-motion ui-focus-ring tw-inline-flex tw-h-11 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-6 tw-text-ui-sm tw-font-semibold tw-text-white hover:tw-brightness-95 active:tw-scale-95"
            >
                <span>{{ __('common.actions.continue') }}</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </button>

            {{-- Final Submit Button --}}
            <button
                type="submit"
                x-show="step === maxStep"
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
    Alpine.data('supplierRegistrationWizard', (config = {}) => ({
        step: Number(config.initialStep) || 1,
        maxStep: {{ $totalSteps }},
        errorSteps: Array.isArray(config.errorSteps) ? config.errorSteps.map(Number) : [],
        questionnaireKeys: @js($questionnaireKeys),
        isSubmitting: false,
        legalDeclaration: false,
        hasDraft: false,
        isInitialized: false,
        clientStepError: '',
        currentLocale: @js(app()->getLocale()),
        lastWarningToast: '',
        lastWarningToastTime: 0,

        stepTitles: {
            1: @js(__('registration.js.step1')),
            2: @js(__('registration.stepper.questionnaire')),
            3: @js(__('registration.js.step2')),
            4: @js(__('registration.js.step3')),
            5: @js(__('registration.js.step4'))
        },

        stepHeadings: {
            1: @js(__('registration.account_profile')),
            2: @js(__('registration.questionnaire.title')),
            3: @js(__('registration.legal_pic_bank')),
            4: @js(__('registration.js.heading3')),
            5: @js(__('registration.js.heading4'))
        },

        stepSubheadings: {
            1: @js(__('registration.js.help1')),
            2: @js(__('registration.questionnaire.help')),
            3: @js(__('registration.js.help2')),
            4: @js(__('registration.js.help3')),
            5: @js(__('registration.js.help4'))
        },

        summaryLabels: {
            categoryGeneral: @js(__('registration.js.category_general')),
            pkp: @js(__('registration.js.pkp_status')),
            nonPkp: @js(__('registration.js.non_pkp_status')),
            yes: @js(__('registration.questionnaire.yes')),
            no: @js(__('registration.questionnaire.no')),
            notAnswered: @js(__('registration.questionnaire.not_answered')),
            questionnaireProgress: @js(__('registration.questionnaire.progress')),
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
            questionnaire: @js((object) $questionnaireAnswers),
        },

        errors: {},

        docs: {
            nib: null,
            npwp: null,
            sknr: null,
            sppkp: null,
            skd: null,
            company_profile: null,
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

        // Mirrors Password::mixedCase() / ->symbols() in SupplierRegistrationRequest.
        get passwordHasMixedCase() {
            return /\p{Lu}/u.test(this.formData.password) && /\p{Ll}/u.test(this.formData.password);
        },

        get passwordHasSymbol() {
            return /[\p{Z}\p{S}\p{P}]/u.test(this.formData.password);
        },

        get answeredQuestionCount() {
            return this.questionnaireKeys.filter((key) => ['yes', 'no'].includes(this.formData.questionnaire?.[key])).length;
        },

        get questionnaireProgressLabel() {
            return this.summaryLabels.questionnaireProgress
                .replace(':count', this.answeredQuestionCount)
                .replace(':total', this.questionnaireKeys.length);
        },

        answerLabel(key) {
            const answer = this.formData.questionnaire?.[key];
            if (answer === 'yes') return this.summaryLabels.yes;
            if (answer === 'no') return this.summaryLabels.no;
            return this.summaryLabels.notAnswered;
        },

        hasStepError(stepNumber) {
            return this.errorSteps.includes(stepNumber);
        },

        stepMarkerClass(stepNumber) {
            if (this.hasStepError(stepNumber)) {
                return 'tw-border-error tw-bg-error-container tw-text-on-error-container';
            }
            if (this.step === stepNumber) {
                return 'tw-border-primary tw-bg-primary tw-text-on-primary';
            }
            if (this.step > stepNumber) {
                return 'tw-border-primary tw-bg-primary/10 tw-text-primary';
            }

            return 'tw-border-outline-variant tw-bg-surface tw-text-on-surface-variant';
        },

        setStepError(stepNumber, hasError) {
            const others = this.errorSteps.filter((value) => value !== stepNumber);
            this.errorSteps = hasError ? [...others, stepNumber].sort((a, b) => a - b) : others;
        },

        stepOfElement(element) {
            const container = element?.closest?.('[data-wizard-step]');
            return container ? Number(container.dataset.wizardStep) : null;
        },

        // adasi:reveal-field (async summary links / first 422 error) opens the step holding that field.
        revealStepFor(element) {
            const target = this.stepOfElement(element);
            if (target && target !== this.step) {
                if (target === this.maxStep) {
                    this.updateDocsSummary();
                }
                this.step = target;
            }
        },

        // adasi:form-errors (422 from the async submit): show messages inline and flag their steps.
        onServerErrors(serverErrors = {}) {
            const form = this.$root.querySelector('#supplierRegistrationForm') || document.getElementById('supplierRegistrationForm');
            const steps = new Set();
            this.errors = {};

            Object.entries(serverErrors || {}).forEach(([key, messages]) => {
                this.errors[key] = String([].concat(messages)[0] ?? '');
                const field = window.AdasiAsyncForm?.findFieldForErrorKey(form, key);
                const fieldStep = this.stepOfElement(field);
                if (fieldStep) steps.add(fieldStep);
            });

            this.errorSteps = [...steps].sort((a, b) => a - b);
            this.clientStepError = '';
        },

        clearStoredDraft() {
            try {
                localStorage.removeItem('adasi_supplier_reg_draft');
            } catch (e) {}
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
                company_profile_file: 'company_profile',
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

                this.isInitialized = true;
            });

            // Watch formData deeply for debounced draft persistence
            this.$watch('formData', () => {
                if (!this.isInitialized) return;
                this.scheduleDraftPersist();
            }, { deep: true });

            // Ensure pending draft is saved if user abruptly navigates away
            window.addEventListener('beforeunload', () => {
                if (this.isInitialized) {
                    this.persistDraft();
                }
            });
        },

        scheduleDraftPersist() {
            clearTimeout(this._draftTimer);
            this._draftTimer = setTimeout(() => {
                this.persistDraft();
            }, 350);
        },

        checkStoredDraft() {
            try {
                // If form is already filled from old() validation input, don't show draft prompt
                if (Boolean((this.formData.email && this.formData.email.trim()) || (this.formData.company_name && this.formData.company_name.trim()))) {
                    this.hasDraft = false;
                    return;
                }

                const stored = localStorage.getItem('adasi_supplier_reg_draft');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    if (parsed && typeof parsed === 'object') {
                        // Check if there is actual non-empty, meaningful user data
                        const hasMeaningfulData = Object.entries(parsed).some(([key, val]) => {
                            if (['saved_step'].includes(key)) return false;
                            if (key === 'questionnaire') return Object.keys(this.sanitizeAnswers(val)).length > 0;
                            if (key === 'company_title' && (val === 'PT' || !val)) return false;
                            if (key === 'is_pkp' && !val) return false;
                            return typeof val === 'string' ? val.trim().length > 0 : Boolean(val);
                        });
                        this.hasDraft = hasMeaningfulData;
                        return;
                    }
                }
            } catch (e) {
                this.hasDraft = false;
            }
            this.hasDraft = false;
        },

        restoreDraft(showToast = true) {
            try {
                const stored = localStorage.getItem('adasi_supplier_reg_draft');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    Object.keys(parsed).forEach(key => {
                        if (key in this.formData && !['password', 'password_confirmation', 'saved_step', 'questionnaire'].includes(key)) {
                            if (parsed[key] !== undefined && parsed[key] !== null) {
                                this.formData[key] = parsed[key];
                            }
                        }
                    });
                    this.formData.questionnaire = this.sanitizeAnswers(parsed.questionnaire);

                    // Clear any previous validation errors for restored fields
                    this.errors = {};
                    this.clientStepError = '';

                    // Sync bank_select to searchable-select component
                    if (this.formData.bank_select) {
                        this.$nextTick(() => {
                            window.dispatchEvent(new CustomEvent('set-select-value', {
                                detail: { name: 'bank_select', value: this.formData.bank_select }
                            }));
                        });
                    }

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
                // Check if any non-default meaningful text has been typed
                const hasData = Object.entries(this.formData).some(([key, val]) => {
                    if (['password', 'password_confirmation'].includes(key)) return false;
                    if (key === 'questionnaire') return this.answeredQuestionCount > 0;
                    if (key === 'company_title' && (val === 'PT' || !val)) return false;
                    if (key === 'is_pkp' && !val) return false;
                    return typeof val === 'string' ? val.trim().length > 0 : Boolean(val);
                });

                if (!hasData) {
                    return;
                }

                // Never store passwords in localStorage
                const safePayload = {
                    email: this.formData.email,
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
                    questionnaire: this.sanitizeAnswers(this.formData.questionnaire),
                    saved_step: this.step,
                };
                localStorage.setItem('adasi_supplier_reg_draft', JSON.stringify(safePayload));
            } catch (e) {
                // Storage quota or private mode restrictions
            }
        },

        // Only known question keys with yes/no values survive draft restore.
        sanitizeAnswers(raw) {
            const answers = {};
            if (!raw || typeof raw !== 'object') return answers;
            this.questionnaireKeys.forEach((key) => {
                if (['yes', 'no'].includes(raw[key])) answers[key] = raw[key];
            });
            return answers;
        },

        validateField(field) {
            delete this.errors[field];

            if (field.startsWith('questionnaire.')) {
                const key = field.slice('questionnaire.'.length);
                if (!['yes', 'no'].includes(this.formData.questionnaire?.[key])) {
                    this.errors[field] = @js(__('registration.questionnaire.answer_required'));
                }
                return;
            }

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
                } else if (!this.passwordHasMixedCase || !this.passwordHasNumber || !this.passwordHasSymbol) {
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
            const valid = this.runStepValidation(stepNumber);
            this.setStepError(stepNumber, !valid);

            return valid;
        },

        runStepValidation(stepNumber) {
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
                const questionFields = this.questionnaireKeys.map((key) => 'questionnaire.' + key);
                questionFields.forEach((field) => this.validateField(field));
                const firstUnanswered = questionFields.find((field) => this.errors[field]);

                if (firstUnanswered) {
                    this.clientStepError = @js(__('registration.questionnaire.required'));
                    this.focusField(firstUnanswered.replace('questionnaire.', 'questionnaire[') + ']');
                    return false;
                }
                return true;
            }

            if (stepNumber === 3) {
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

            if (stepNumber === 4) {
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
                company_profile_file: 'company_profile',
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

            if (targetStep === this.maxStep) {
                this.updateDocsSummary();
            }

            this.step = targetStep;
            this.scrollToTop();
        },

        nextStep() {
            if (this.validateStep(this.step)) {
                if (this.step < this.maxStep) {
                    if (this.step === this.maxStep - 1) {
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
            const stepFallbacks = {
                1: @js(__('registration.js.step1_first')),
                2: @js(__('registration.js.questionnaire_first')),
                3: @js(__('registration.js.step2_first')),
                4: @js(__('registration.js.step3_first')),
            };

            for (let s = 1; s < this.maxStep; s++) {
                if (!this.validateStep(s)) {
                    event.preventDefault();
                    // Go straight to the failing step (goToStep would re-validate earlier steps).
                    this.step = s;
                    this.scrollToTop();
                    if (window.AdasiToast) {
                        window.AdasiToast.error(this.clientStepError || stepFallbacks[s]);
                    }
                    return;
                }
            }

            if (!this.legalDeclaration) {
                event.preventDefault();
                this.clientStepError = @js(__('registration.js.declaration_required'));
                this.showWarningToast(this.clientStepError);
                return;
            }

            this.isSubmitting = true;

            // Async submit clears the draft on adasi:form-success; the classic POST fallback clears it now.
            if (!window.AdasiAsyncForm) {
                this.clearStoredDraft();
            }
        }
    }));
});
</script>
@endsection
