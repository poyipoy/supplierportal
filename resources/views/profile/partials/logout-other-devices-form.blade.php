<section>
    <p class="tw-m-0 tw-mb-4 tw-text-ui-xs tw-text-on-surface-variant">Sign out all other browsers and devices while keeping this session active.</p>

    <form method="POST" action="{{ route('profile.logout-other-devices') }}">
        @csrf

        <div class="tw-grid tw-gap-1.5 tw-mb-4">
            <label for="logout_other_devices_password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Current password</label>
            <input id="logout_other_devices_password" name="password" type="password"
                class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->logoutOtherDevices->has('password') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                autocomplete="current-password" maxlength="255" required>
            @error('password', 'logoutOtherDevices')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-medium tw-text-on-surface hover:tw-bg-surface-container">
            <x-ui.icon name="log-out" />Log Out Other Devices
        </button>
    </form>
</section>
