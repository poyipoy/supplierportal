@extends('layouts.auth')

@section('title', __('auth.login.title'))
@section('shell-attributes', 'data-login-screen')

@section('content')
<header class="tw:mb-5" @guest data-auth-login-page data-session-cross-tab="{{ config('portal_session.cross_tab_logout') ? '1' : '0' }}" data-session-cleanup="{{ config('portal_session.scoped_storage_cleanup') ? '1' : '0' }}" @endguest>
    <p class="tw:m-0 tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-primary">{{ __('common.review.supplier_portal') }}</p>
    <h2 class="tw:m-0 tw:mt-1.5 tw:text-ui-xl tw:font-bold tw:tracking-tight tw:text-on-surface">{{ __('auth.login.heading') }}</h2>
    <p class="tw:m-0 tw:mt-1.5 tw:text-ui-sm tw:text-on-surface-variant">{{ __('auth.login.instructions') }}</p>
</header>

<form
    method="POST"
    action="{{ route('login.store') }}"
    data-track-unsaved="false"
    novalidate
    class="tw:grid tw:gap-4"
    x-data="{
        visible: false,
        emailError: false,
        passwordError: false,
        handleSubmit(e) {
            const emailVal = this.$refs.email ? this.$refs.email.value.trim() : '';
            const passwordVal = this.$refs.password ? this.$refs.password.value : '';
            this.emailError = !emailVal;
            this.passwordError = !passwordVal;
            if (this.emailError || this.passwordError) {
                e.preventDefault();
                if (this.emailError && this.$refs.email) {
                    this.$refs.email.focus();
                } else if (this.passwordError && this.$refs.password) {
                    this.$refs.password.focus();
                }
                return false;
            }
        }
    }"
    @submit="handleSubmit($event)"
>
    @csrf

    <div class="tw:grid tw:gap-1.5">
        <label for="email" class="tw:text-ui-sm tw:font-medium tw:text-on-surface">{{ __('auth.login.email') }}</label>
        <div class="tw:relative">
            <div class="tw:absolute tw:inset-y-0 tw:start-0 tw:flex tw:items-center tw:pl-3 tw:pointer-events-none tw:text-on-surface-variant">
                <x-ui.icon name="mail" />
            </div>
            <input
                id="email"
                type="email"
                name="email"
                x-ref="email"
                class="ui-motion tw:h-11 tw:w-full tw:rounded-ui-sm tw:border tw:bg-surface tw:pl-10 tw:pr-3 tw:text-ui-sm tw:text-on-surface tw:hover:border-outline-strong tw:focus:border-primary tw:focus:ring-2 tw:focus:ring-primary {{ (isset($errors) && $errors->has('email')) ? 'tw:border-error' : 'tw:border-outline' }}"
                :class="{ 'tw:border-error': emailError || {{ $errors->has('email') ? 'true' : 'false' }}, 'tw:border-outline': !(emailError || {{ $errors->has('email') ? 'true' : 'false' }}) }"
                placeholder="{{ __('common.fields.email') }}"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
                @input="if ($el.value.trim().length > 0) emailError = false"
                :aria-invalid="(emailError || {{ $errors->has('email') ? 'true' : 'false' }}) ? 'true' : 'false'"
                aria-describedby="email-error"
            >
        </div>
        <p
            id="email-error"
            class="tw:m-0 tw:text-ui-xs tw:font-medium tw:text-error"
            role="alert"
            x-show="emailError || {{ $errors->has('email') ? 'true' : 'false' }}"
            {{ $errors->has('email') ? '' : 'x-cloak style="display: none;"' }}
        >
            <span x-text="emailError ? @js(__('auth.login.email_empty')) : @js($errors->first('email') ?? __('auth.login.email_empty'))">
                {{ $errors->first('email') ?? __('auth.login.email_empty') }}
            </span>
        </p>
    </div>

    <div class="tw:grid tw:gap-1.5">
        <div class="tw:flex tw:items-center tw:justify-between tw:gap-3">
            <label for="password" class="tw:text-ui-sm tw:font-medium tw:text-on-surface">{{ __('auth.login.password') }}</label>
            <a href="{{ route('password.request') }}" class="ui-focus-ring tw:rounded-ui-xs tw:text-ui-xs tw:font-semibold tw:text-primary tw:no-underline tw:hover:underline">{{ __('auth.login.reset') }}</a>
        </div>
        <div class="tw:relative">
            <div class="tw:absolute tw:inset-y-0 tw:start-0 tw:flex tw:items-center tw:pl-3 tw:pointer-events-none tw:text-on-surface-variant">
                <x-ui.icon name="lock" />
            </div>
            <input
                id="password"
                type="password"
                :type="visible ? 'text' : 'password'"
                name="password"
                x-ref="password"
                class="ui-motion tw:h-11 tw:w-full tw:rounded-ui-sm tw:border tw:bg-surface tw:pl-10 tw:pr-11 tw:text-ui-sm tw:text-on-surface tw:hover:border-outline-strong tw:focus:border-primary tw:focus:ring-2 tw:focus:ring-primary {{ (isset($errors) && $errors->has('password')) ? 'tw:border-error' : 'tw:border-outline' }}"
                :class="{ 'tw:border-error': passwordError || {{ $errors->has('password') ? 'true' : 'false' }}, 'tw:border-outline': !(passwordError || {{ $errors->has('password') ? 'true' : 'false' }}) }"
                placeholder="{{ __('auth.login.password') }}"
                autocomplete="current-password"
                maxlength="255"
                required
                @input="if ($el.value.length > 0) passwordError = false"
                :aria-invalid="(passwordError || {{ $errors->has('password') ? 'true' : 'false' }}) ? 'true' : 'false'"
                aria-describedby="password-error"
            >
            <button type="button" disabled :disabled="false" aria-label="{{ __('auth.login.show_password') }}" class="ui-focus-ring tw:absolute tw:inset-y-0 tw:end-0.5 tw:my-auto tw:inline-flex tw:h-9 tw:w-9 tw:items-center tw:justify-center tw:rounded-ui-full tw:border-0 tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container" @click="visible = !visible" :aria-label="visible ? @js(__('auth.login.hide_password')) : @js(__('auth.login.show_password'))">
                <x-ui.icon name="eye-off" x-show="visible" />
                <x-ui.icon name="eye" x-show="!visible" />
            </button>
        </div>
        <p
            id="password-error"
            class="tw:m-0 tw:text-ui-xs tw:font-medium tw:text-error"
            role="alert"
            x-show="passwordError || {{ $errors->has('password') ? 'true' : 'false' }}"
            {{ $errors->has('password') ? '' : 'x-cloak style="display: none;"' }}
        >
            <span x-text="passwordError ? @js(__('auth.login.password_empty')) : @js($errors->first('password') ?? __('auth.login.password_empty'))">
                {{ $errors->first('password') ?? __('auth.login.password_empty') }}
            </span>
        </p>
    </div>

    <label class="tw:flex tw:items-center tw:gap-2 tw:text-ui-sm tw:cursor-pointer" for="remember">
        <input type="checkbox" name="remember" value="1" class="form-check-input tw:mt-0" id="remember" {{ old('remember') ? 'checked' : '' }}>
        <span class="tw:font-medium tw:text-on-surface">{{ __('auth.login.remember_device') }}</span>
    </label>

    @if (isset($turnstileRequired) && $turnstileRequired && isset($turnstileSiteKey) && $turnstileSiteKey)
        <div>
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
            @error('cf-turnstile-response')<p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:font-medium tw:text-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @endif

    <button type="submit" class="ui-focus-ring ui-motion tw:mt-1 tw:flex tw:h-11 tw:w-full tw:items-center tw:justify-center tw:gap-2 tw:rounded-ui-sm tw:border-0 tw:bg-primary tw:text-ui-sm tw:font-semibold tw:text-primary-foreground tw:hover:brightness-95 tw:active:brightness-90">
        {{ __('auth.login.sign_in') }}
    </button>
</form>

<div class="auth-registration-actions">
    <a href="{{ route('supplier.register') }}" class="auth-next-step ui-focus-ring">
        <span class="auth-next-step__icon"><x-ui.icon name="user-plus" size="sm" /></span>
        <span class="auth-next-step__text">
            <span class="auth-next-step__label">{{ __('auth.login.new_supplier') }}</span>
            <span class="auth-next-step__hint">{{ __('common.review.register_company') }}</span>
        </span>
        <x-ui.icon name="chevron-right" size="sm" class="auth-next-step__chevron" />
    </a>
    <a href="{{ route('supplier.registration.access-form') }}" class="auth-next-step ui-focus-ring">
        <span class="auth-next-step__icon"><x-ui.icon name="search" size="sm" /></span>
        <span class="auth-next-step__text">
            <span class="auth-next-step__label">{{ __('auth.login.already_applied') }}</span>
            <span class="auth-next-step__hint">{{ __('auth.login.registration_status') }}</span>
        </span>
        <x-ui.icon name="chevron-right" size="sm" class="auth-next-step__chevron" />
    </a>
</div>
@endsection

@section('scripts')
@if (isset($turnstileRequired) && $turnstileRequired && isset($turnstileSiteKey) && $turnstileSiteKey)
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif
@endsection
