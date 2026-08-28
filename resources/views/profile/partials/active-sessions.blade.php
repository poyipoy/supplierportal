<section id="active-sessions">
    <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">
        Devices currently signed in to your account. Up to {{ config('auth_security.session.max_concurrent_sessions', 3) }} sessions can stay active at once — signing in on a new device beyond that limit will automatically sign the oldest one out.
    </p>

    @if (session('status') === 'session-revoked')
        <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-font-medium tw-text-primary" role="status">That device has been signed out.</p>
    @elseif (session('warning'))
        <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ session('warning') }}</p>
    @endif

    <div class="tw-grid tw-gap-2">
        @forelse ($activeSessions as $activeSession)
            <div class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-px-3 tw-py-2.5">
                <div class="tw-flex tw-items-start tw-gap-2.5">
                    <x-ui.icon name="monitor" size="sm" />
                    <div>
                        <p class="tw-m-0 tw-text-ui-sm tw-font-medium tw-text-on-surface">
                            {{ $activeSession->ip_address ?: 'Unknown IP' }}
                            @if ($activeSession->is_current)
                                <span class="tw-ml-1 tw-rounded-ui-xs tw-bg-primary/10 tw-px-1.5 tw-py-0.5 tw-text-ui-2xs tw-font-semibold tw-text-primary">This device</span>
                            @endif
                        </p>
                        <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">
                            {{ \Illuminate\Support\Str::limit($activeSession->user_agent ?: 'Unknown device', 60) }} &middot; Last active {{ $activeSession->last_active_at->diffForHumans() }}
                        </p>
                    </div>
                </div>

                @unless ($activeSession->is_current)
                    <form method="POST" action="{{ route('profile.sessions.revoke', $activeSession->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-8 tw-shrink-0 tw-items-center tw-gap-1.5 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-2.5 tw-text-ui-xs tw-font-medium tw-text-error hover:tw-bg-error/10">
                            <x-ui.icon name="trash-2" size="sm" />Sign out
                        </button>
                    </form>
                @endunless
            </div>
        @empty
            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">No active sessions found.</p>
        @endforelse
    </div>
</section>
