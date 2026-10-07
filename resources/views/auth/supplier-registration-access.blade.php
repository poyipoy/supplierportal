@extends('layouts.auth')

@section('title', __('registration.access_title'))

@section('content')
@php
    $credentialErrors = $errors->getBag('credentials');
    $initialTab = session('access_tab') ?? ($errors->hasAny(['email', 'password']) ? 'credentials' : 'key');
    $emailError = $credentialErrors->first('email') ?: $errors->first('email');
    $passwordError = $errors->first('password');
@endphp

<style>
    .access-tab { transition-property: background-color, color, box-shadow, transform; transition-duration: 160ms; transition-timing-function: ease-out; }
    .access-tab:active { transform: scale(0.96); }
    .access-panel { animation: accessPanelIn 180ms ease-out both; }
    @keyframes accessPanelIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
        .access-tab, .access-panel { transition: none; animation: none; }
        .access-tab:active { transform: none; }
    }
</style>

<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">{{ __('registration.onboarding') }}</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface" style="text-wrap: balance;">{{ __('registration.access_heading') }}</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant" style="text-wrap: pretty;">{{ __('registration.access_help') }}</p>
</header>

@if (session('error'))
    <div class="tw-rounded-ui-sm tw-bg-error-container tw-p-3 tw-text-on-error-container tw-mb-4 tw-flex tw-items-center tw-gap-2 tw-text-ui-xs tw-font-medium" role="alert">
        <x-ui.icon name="alert-circle" size="sm" class="tw-shrink-0" />
        <span>{{ session('error') }}</span>
    </div>
@endif

@if (session('info'))
    <div class="tw-rounded-ui-sm tw-bg-info-container tw-p-3 tw-text-on-info-container tw-mb-4 tw-flex tw-items-center tw-gap-2 tw-text-ui-xs tw-font-medium" role="alert">
        <x-ui.icon name="info" size="sm" class="tw-shrink-0" />
        <span>{{ session('info') }}</span>
    </div>
@endif

<div x-data="{ tab: @js($initialTab) }">
    <div class="tw-mb-4 tw-grid tw-grid-cols-2 tw-gap-1 tw-rounded-ui-sm tw-bg-surface-container tw-p-1" role="tablist" aria-label="{{ __('registration.credential_access.tablist') }}">
        <button
            type="button"
            id="access_tab_key"
            role="tab"
            class="access-tab ui-focus-ring tw-flex tw-min-h-10 tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-ui-xs tw-border-0 tw-px-3 tw-text-ui-xs tw-font-semibold"
            :class="tab === 'key' ? 'tw-bg-surface tw-text-primary tw-shadow-sm' : 'tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface'"
            :aria-selected="tab === 'key'"
            aria-controls="access_panel_key"
            @click="tab = 'key'"
        >
            <x-ui.icon name="key" size="xs" />
            <span>{{ __('registration.credential_access.tab_key') }}</span>
        </button>
        <button
            type="button"
            id="access_tab_credentials"
            role="tab"
            class="access-tab ui-focus-ring tw-flex tw-min-h-10 tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-ui-xs tw-border-0 tw-px-3 tw-text-ui-xs tw-font-semibold"
            :class="tab === 'credentials' ? 'tw-bg-surface tw-text-primary tw-shadow-sm' : 'tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface'"
            :aria-selected="tab === 'credentials'"
            aria-controls="access_panel_credentials"
            @click="tab = 'credentials'"
        >
            <x-ui.icon name="mail" size="xs" />
            <span>{{ __('registration.credential_access.tab_credentials') }}</span>
        </button>
    </div>

    {{-- ACCESS KEY --}}
    <form
        id="access_panel_key"
        role="tabpanel"
        aria-labelledby="access_tab_key"
        method="POST"
        action="{{ route('supplier.registration.access') }}"
        class="access-panel tw-grid tw-gap-4"
        x-show="tab === 'key'"
        @if ($initialTab !== 'key') x-cloak style="display: none;" @endif
    >
        @csrf

        <div class="tw-grid tw-gap-1.5">
            <label for="reference" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('registration.reference') }}</label>
            <div class="tw-relative">
                <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                    <x-ui.icon name="hash" size="sm" />
                </div>
                <input
                    id="reference"
                    type="text"
                    name="reference"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-font-mono tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('reference') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    placeholder="{{ __('registration.registration_example') }}"
                    value="{{ old('reference') }}"
                    autocomplete="off"
                    required
                    @if ($initialTab === 'key') autofocus @endif
                >
            </div>
            @error('reference')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
        </div>

        <div class="tw-grid tw-gap-1.5" x-data="{ showKey: false }">
            <label for="access_key" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('registration.key') }}</label>
            <div class="tw-relative">
                <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                    <x-ui.icon name="key" size="sm" />
                </div>
                <input
                    id="access_key"
                    :type="showKey ? 'text' : 'password'"
                    name="access_key"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-font-mono tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('access_key') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    placeholder="{{ __('registration.access_placeholder') }}"
                    autocomplete="off"
                    required
                >
                <button
                    type="button"
                    class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                    @click="showKey = !showKey"
                    tabindex="-1"
                    :aria-label="showKey ? @js(__('registration.hide_key')) : @js(__('registration.show_key'))"
                >
                    <x-ui.icon name="eye" size="sm" x-show="!showKey" />
                    <x-ui.icon name="eye-off" size="sm" x-show="showKey" />
                </button>
            </div>
            @error('access_key')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90 active:tw-scale-[0.96]">
            <x-ui.icon name="arrow-right" size="sm" />
            <span>{{ __('registration.view_status') }}</span>
        </button>
    </form>

    {{-- EMAIL + PASSWORD (lost access key fallback) --}}
    <form
        id="access_panel_credentials"
        role="tabpanel"
        aria-labelledby="access_tab_credentials"
        method="POST"
        action="{{ route('supplier.registration.access.credentials') }}"
        class="access-panel tw-grid tw-gap-4"
        x-show="tab === 'credentials'"
        @if ($initialTab !== 'credentials') x-cloak style="display: none;" @endif
    >
        @csrf

        <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant" style="text-wrap: pretty;">{{ __('registration.credential_access.help') }}</p>

        <div class="tw-grid tw-gap-1.5">
            <label for="credential_email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('registration.credential_access.email') }}</label>
            <div class="tw-relative">
                <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                    <x-ui.icon name="mail" size="sm" />
                </div>
                <input
                    id="credential_email"
                    type="email"
                    name="email"
                    inputmode="email"
                    autocomplete="username"
                    spellcheck="false"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $emailError ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    placeholder="{{ __('registration.credential_access.email_placeholder') }}"
                    value="{{ old('email') }}"
                    required
                    @if ($initialTab === 'credentials') autofocus @endif
                >
            </div>
            @if ($emailError)<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $emailError }}</p>@endif
        </div>

        <div class="tw-grid tw-gap-1.5" x-data="{ showPassword: false }">
            <label for="credential_password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('registration.credential_access.password') }}</label>
            <div class="tw-relative">
                <div class="tw-absolute tw-inset-y-0 tw-start-0 tw-flex tw-items-center tw-pl-3 tw-pointer-events-none tw-text-on-surface-variant">
                    <x-ui.icon name="lock" size="sm" />
                </div>
                <input
                    id="credential_password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    autocomplete="current-password"
                    class="ui-motion tw-h-11 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-pl-10 tw-pr-11 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $passwordError ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    placeholder="{{ __('registration.credential_access.password_placeholder') }}"
                    required
                >
                <button
                    type="button"
                    class="ui-focus-ring tw-absolute tw-inset-y-0 tw-end-1 tw-my-auto tw-inline-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-ui-full tw-border-0 tw-bg-transparent tw-text-on-surface-variant hover:tw-bg-surface-container"
                    @click="showPassword = !showPassword"
                    tabindex="-1"
                    :aria-label="showPassword ? @js(__('registration.credential_access.hide_password')) : @js(__('registration.credential_access.show_password'))"
                >
                    <x-ui.icon name="eye" size="sm" x-show="!showPassword" />
                    <x-ui.icon name="eye-off" size="sm" x-show="showPassword" />
                </button>
            </div>
            @if ($passwordError)<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $passwordError }}</p>@endif
        </div>

        @if (! empty($turnstileRequired) && ! empty($turnstileSiteKey))
            <div>
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
                @error('cf-turnstile-response')<p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif

        <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90 active:tw-scale-[0.96]">
            <x-ui.icon name="arrow-right" size="sm" />
            <span>{{ __('registration.credential_access.submit') }}</span>
        </button>
    </form>
</div>

<div class="tw-mt-4 tw-pt-4 tw-border-t tw-border-outline-variant tw-text-center tw-text-ui-xs tw-text-on-surface-variant">
    <p class="tw-m-0">{{ __('registration.new_supplier') }} <a href="{{ route('supplier.register') }}" class="tw-font-semibold tw-text-primary hover:tw-underline">{{ __('registration.start') }}</a></p>
    <p class="tw-m-0 tw-mt-1.5"><a href="{{ route('login') }}" class="tw-text-on-surface-variant hover:tw-text-primary hover:tw-underline">{{ __('registration.active_login') }}</a></p>
</div>
@endsection

@section('scripts')
    @if (! empty($turnstileRequired) && ! empty($turnstileSiteKey))
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
@endsection
