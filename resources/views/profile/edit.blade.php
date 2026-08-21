@extends('layouts.app')

@section('title', 'Profile & Security - ADASI Supplier Portal')
@section('page-title', 'Profile & Security')

@section('content')
    <div class="tw-grid tw-gap-6">
        <x-ui.page-header
            title="Profile & Security"
            description="Manage your account information, password, sign-in security, and active sessions."
            eyebrow="Account"
        >
            <x-slot:meta>
                <x-ui.status-chip tone="info"><x-ui.icon name="user-check" size="sm" />{{ ucfirst($user->role) }} account</x-ui.status-chip>
            </x-slot:meta>
        </x-ui.page-header>

        <div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,1.35fr)_minmax(20rem,1fr)] xl:tw-items-start">
            <div class="tw-grid tw-gap-6">
                <x-ui.card title="Personal Information" description="Name and email address for this account.">
                    @include('profile.partials.update-profile-information-form')
                </x-ui.card>

                <x-ui.card title="Change Password" description="Use a strong password with at least 12 characters.">
                    @include('profile.partials.update-password-form')
                </x-ui.card>
            </div>

            <div class="tw-grid tw-gap-6">
                <x-ui.card title="Two-Factor Authentication" description="Optional additional protection for your sign-in.">
                    @include('profile.partials.two-factor-authentication-form')
                </x-ui.card>

                <x-ui.card title="Other Devices" description="End access from other active sessions.">
                    @include('profile.partials.logout-other-devices-form')
                </x-ui.card>
            </div>
        </div>

        <x-ui.card title="Danger Zone" description="Permanently delete this account and its eligible data." class="tw-border-error">
            @include('profile.partials.delete-user-form')
        </x-ui.card>
    </div>
@endsection
