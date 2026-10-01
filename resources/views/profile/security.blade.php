@extends('layouts.app')

@section('title', 'Security - ADASI Supplier Portal')
@section('page-title', 'Security')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Security"
        description="Manage your password and existing account security settings."
        eyebrow="Account"
    />

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-password-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-password-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Change Password</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.update-password-form')
        </div>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-two-factor-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-two-factor-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Two-Factor Authentication</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.two-factor-authentication-form')
        </div>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-active-sessions-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-active-sessions-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Active Sessions</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.active-sessions')
        </div>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="security-logout-other-devices-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="security-logout-other-devices-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Log Out Other Devices</h2>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.logout-other-devices-form')
        </div>
    </section>
</div>
@endsection
