<section id="known-devices" aria-labelledby="security-known-devices-title" tabindex="-1" class="ui-focus-ring tw:scroll-mt-20">
    <p class="tw:m-0 tw:mb-4 tw:text-ui-xs tw:text-on-surface-variant">
        {{ __('security.known_devices_help') }}
    </p>

    @if (session('status') === 'device-revoked')
        <p class="tw:m-0 tw:mb-4 tw:text-ui-xs tw:font-semibold tw:text-primary" role="status">{{ __('security.device_revoked') }}</p>
    @elseif (session('status') === 'device-not-found')
        <p class="tw:m-0 tw:mb-4 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant" role="status">{{ __('security.device_not_found') }}</p>
    @endif

    <div class="tw:grid tw:gap-2">
        @forelse ($knownDevices as $device)
            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:px-3 tw:py-2.5">
                <div class="tw:flex tw:min-w-0 tw:items-start tw:gap-2.5">
                    <x-ui.icon name="laptop" size="sm" />
                    <div class="tw:min-w-0 tw:wrap-break-word">
                        <p class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">
                            {{ $device->device_label }}
                            @if ($device->is_current)
                                <span class="tw:ml-1 tw:rounded-ui-xs tw:bg-primary/10 tw:px-1.5 tw:py-0.5 tw:text-ui-2xs tw:font-semibold tw:text-primary">{{ __('security.this_device') }}</span>
                            @endif
                        </p>
                        <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                            {{ $device->last_ip_address ?: __('security.unknown_ip') }}
                            &middot; {{ __('security.last_active', ['time' => $device->last_seen_at->locale(app()->getLocale())->diffForHumans()]) }}
                            &middot; {{ __('security.first_seen', ['time' => $device->first_seen_at->locale(app()->getLocale())->diffForHumans()]) }}
                        </p>
                    </div>
                </div>

                @unless ($device->is_current)
                    <form method="POST" action="{{ route('profile.known-devices.revoke') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="device_token" value="{{ $device->revocation_token }}">
                        <button type="submit" class="ui-focus-ring ui-motion tw:inline-flex tw:h-8 tw:shrink-0 tw:items-center tw:gap-1.5 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-transparent tw:px-2.5 tw:text-ui-xs tw:font-semibold tw:text-error tw:hover:bg-error/10">
                            <x-ui.icon name="trash-2" size="sm" />{{ __('security.remove_device') }}
                        </button>
                    </form>
                @endunless
            </div>
        @empty
            <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('security.no_known_devices') }}</p>
        @endforelse
    </div>
</section>

