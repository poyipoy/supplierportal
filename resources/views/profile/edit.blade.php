@extends('layouts.app')

@section('title', 'My Profile - ADASI Supplier Portal')
@section('page-title', 'My Profile')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="My Profile"
        description="Manage your account information."
        eyebrow="Account"
    >
        <x-slot:meta>
            <x-ui.status-chip tone="neutral"><x-ui.icon name="user-check" size="sm" />{{ ucfirst($user->role) }} account</x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button variant="outline" :href="route('profile.security')">
                <x-ui.icon name="shield-check" />Security
            </x-ui.button>
        </x-slot:actions>
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

    </section>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const destination = @js(route('profile.security').'#active-sessions');
    const forwardActiveSessions = () => {
        if (window.location.hash === '#active-sessions') {
            window.location.replace(destination);
        }
    };

    window.addEventListener('hashchange', forwardActiveSessions);
    forwardActiveSessions();
})();
</script>
@endpush
