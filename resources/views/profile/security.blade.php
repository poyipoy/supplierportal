@extends('layouts.app')

@section('title', __('security.page_title'))
@section('page-title', __('navigation.security'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="__('navigation.security')"
        :description="__('security.description')"
        :eyebrow="__('common.fields.account')"
    />

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-password-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-password-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('security.change_password') }}</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.update-password-form')
        </div>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-two-factor-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-two-factor-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('security.two_factor') }}</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.two-factor-authentication-form')
        </div>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-active-sessions-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-active-sessions-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('security.active_sessions') }}</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.active-sessions')
        </div>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-logout-other-devices-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-logout-other-devices-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('security.logout_devices') }}</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.logout-other-devices-form')
        </div>
    </section>
</div>
@endsection
