@extends('layouts.app')

@section('title', 'Profile and Security - ADASI Supplier Portal')
@section('page-title', 'Profile and Security')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Profile and Security"
        description="Manage your account information, password, sign-in security, and active sessions."
        eyebrow="Account"
    >
        <x-slot:meta>
            <x-ui.status-chip tone="neutral"><x-ui.icon name="user-check" size="sm" />{{ ucfirst($user->role) }} account</x-ui.status-chip>
        </x-slot:meta>
    </x-ui.page-header>

    {{-- Primary Account Section --}}
    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="profile-account-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="profile-account-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Account Information</h2>
            <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Keep this information accurate so Purchasing, Suppliers, and QC can identify your account correctly.</p>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="tw-border-t tw-border-outline-variant tw-px-5 tw-py-4">
            <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold">Change Password</h3>
            <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Use a strong password with at least 12 characters containing uppercase, lowercase, numbers, and symbols.</p>
        </div>
        <div class="tw-px-5 tw-pb-5">
            @include('profile.partials.update-password-form')
        </div>
    </section>

    {{-- Security Section --}}
    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="profile-security-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
            <h2 id="profile-security-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Sign-In Security</h2>
            <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Manage two-factor authentication and active sessions.</p>
        </header>

        <div class="tw-divide-y tw-divide-outline-variant">
            <div class="tw-p-5">
                <h3 class="tw-m-0 tw-mb-3 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Two-Factor Authentication</h3>
                @include('profile.partials.two-factor-authentication-form')
            </div>
            <div class="tw-p-5">
                <h3 class="tw-m-0 tw-mb-3 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Other Devices</h3>
                @include('profile.partials.logout-other-devices-form')
            </div>
        </div>
    </section>

    {{-- Danger Zone --}}
    <section class="tw-border tw-border-error/40 tw-bg-surface" aria-labelledby="profile-danger-title">
        <header class="tw-border-b tw-border-error/40 tw-px-5 tw-py-4">
            <h2 id="profile-danger-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-error">Danger Zone</h2>
            <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Permanently delete this account and its eligible data.</p>
        </header>
        <div class="tw-p-5">
            @include('profile.partials.delete-user-form')
        </div>
    </section>
</div>
@endsection
