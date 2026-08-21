<section>
    <p class="text-muted small mb-4">Sign out all other browsers and devices while keeping this session active.</p>

    <form method="POST" action="{{ route('profile.logout-other-devices') }}">
        @csrf

        <div class="mb-3">
            <label for="logout_other_devices_password" class="form-label fw-medium">Current Password</label>
            <input id="logout_other_devices_password" name="password" type="password"
                class="form-control @error('password', 'logoutOtherDevices') is-invalid @enderror"
                autocomplete="current-password" maxlength="255" required>
            @error('password', 'logoutOtherDevices')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-outline-secondary">
            <x-ui.icon name="log-out" class="me-1" />Log Out Other Devices
        </button>
    </form>
</section>
