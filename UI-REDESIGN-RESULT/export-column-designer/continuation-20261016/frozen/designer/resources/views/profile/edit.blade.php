@extends('layouts.app')

@section('title', __('profile.page_title'))
@section('page-title', __('navigation.profile'))

@section('content')
<x-account.shell active="profile">
    <div class="tw-grid tw-gap-6">
        <x-ui.page-header
            :title="__('navigation.profile')"
            :description="__('profile.description')"
        >
            <x-slot:meta>
                <x-ui.status-chip tone="neutral"><x-ui.icon name="user-check" size="sm" />{{ __('navigation.account_role', ['role' => __('navigation.roles.'.$user->role)]) }}</x-ui.status-chip>
            </x-slot:meta>
        </x-ui.page-header>

        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="profile-account-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="profile-account-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('profile.account_information') }}</h2>
                <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('profile.account_help') }}</p>
            </header>
            <div class="tw-p-5">
                <dl class="tw-m-0 tw-grid tw-gap-5 md:tw-grid-cols-2">
                    <div class="tw-min-w-0 md:tw-col-span-2">
                        <dt class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">{{ __('profile.login_email') }}</dt>
                        <dd class="tw-m-0 tw-mt-1 tw-break-all tw-text-ui-sm tw-font-semibold">{{ $user->email }}</dd>
                        <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('profile.email_help') }}</p>
                        @error('email')<p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">{{ __('profile.role') }}</dt>
                        <dd class="tw-m-0 tw-mt-1 tw-text-ui-sm">{{ __('navigation.roles.'.$user->role) }}</dd>
                    </div>
                    <div>
                        <dt class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">{{ __('profile.created_at') }}</dt>
                        <dd class="tw-m-0 tw-mt-1 tw-text-ui-sm">{{ $user->created_at ? $regionalFormatter->timestamp($user->created_at) : '—' }}</dd>
                    </div>
                    @if($user->isSupplier())
                        <div>
                            <dt class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">{{ __('profile.portal_access') }}</dt>
                            <dd class="tw-m-0 tw-mt-1 tw-text-ui-sm">
                                @forelse($supplierScopes as $scope)
                                    <span class="tw-block">{{ __('profile.scope_'.$scope) }}</span>
                                @empty
                                    {{ __('profile.no_portal_access') }}
                                @endforelse
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="tw-text-ui-xs tw-font-medium tw-text-on-surface-variant">{{ __('security.two_factor') }}</dt>
                        <dd class="tw-m-0 tw-mt-1 tw-flex tw-flex-wrap tw-items-center tw-gap-3">
                            <x-ui.status-chip :tone="$user->hasTwoFactorAuthentication() ? 'success' : 'neutral'">
                                {{ $user->hasTwoFactorAuthentication() ? __('security.two_factor_enabled') : __('security.not_enabled') }}
                            </x-ui.status-chip>
                            <a href="{{ route('profile.security').'#security-two-factor-title' }}" class="ui-focus-ring tw-text-ui-sm tw-text-primary">{{ __('profile.manage_security') }}</a>
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
        <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="profile-name-title">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-5 tw-py-4">
                <h2 id="profile-name-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('profile.display_name') }}</h2>
                <p id="profile-name-help" class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('profile.display_name_help') }}</p>
            </header>
            <div class="tw-p-5">
                @include('profile.partials.update-profile-information-form')
            </div>
        </section>
    </div>
</x-account.shell>
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
