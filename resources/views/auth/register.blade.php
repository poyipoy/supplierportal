@extends('layouts.auth')

@section('title', __('auth.register.heading').' - ADASI Supplier Portal')

@section('content')
<header class="tw-mb-5">
    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">{{ __('auth.register.new') }}</p>
    <h2 class="tw-m-0 tw-mt-1.5 tw-text-ui-xl tw-font-bold tw-tracking-tight tw-text-on-surface">{{ __('auth.register.heading') }}</h2>
    <p class="tw-m-0 tw-mt-1.5 tw-text-ui-sm tw-text-on-surface-variant">{{ __('auth.register.help') }}</p>
</header>

<form method="POST" action="{{ route('register') }}" class="tw-grid tw-gap-4">
    @csrf
    <x-ui.input name="name" id="name" :label="__('common.fields.full_name')" autocomplete="name" required autofocus />
    <x-ui.input name="email" id="email" type="email" :label="__('auth.login.email')" autocomplete="username" required />
    <x-ui.input name="password" id="password" type="password" :label="__('auth.login.password')" autocomplete="new-password" minlength="12" maxlength="255" required />
    <x-ui.input name="password_confirmation" id="password_confirmation" type="password" :label="__('auth.register.confirm')" autocomplete="new-password" minlength="12" maxlength="255" required />

    <button type="submit" class="ui-focus-ring ui-motion tw-mt-1 tw-flex tw-h-11 tw-w-full tw-items-center tw-justify-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95 active:tw-brightness-90">
        {{ __('auth.register.submit') }}
    </button>
</form>

<div class="tw-mt-4 tw-text-center">
    <a href="{{ route('login') }}" class="ui-focus-ring tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">{{ __('auth.login.registered') }}</a>
</div>
@endsection
