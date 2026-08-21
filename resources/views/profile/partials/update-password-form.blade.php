<section>
    <p class="text-muted small mb-4">Your new password must contain uppercase and lowercase letters, a number, and a symbol.</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-12">
                <label for="update_password_current_password" class="form-label fw-medium">Current Password</label>
                <input id="update_password_current_password" name="current_password" type="password"
                    class="form-control @error('current_password', 'updatePassword') is-invalid @enderror"
                    autocomplete="current-password" maxlength="255" required>
                @error('current_password', 'updatePassword')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-6">
                <label for="update_password_password" class="form-label fw-medium">New Password</label>
                <input id="update_password_password" name="password" type="password"
                    class="form-control @error('password', 'updatePassword') is-invalid @enderror"
                    autocomplete="new-password" minlength="12" maxlength="255" required>
                @error('password', 'updatePassword')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-6">
                <label for="update_password_password_confirmation" class="form-label fw-medium">Confirm New Password</label>
                <input id="update_password_password_confirmation" name="password_confirmation" type="password"
                    class="form-control" autocomplete="new-password" minlength="12" maxlength="255" required>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3 mt-4">
            <button type="submit" class="btn btn-primary">
                <x-ui.icon name="key" class="me-1" />Update Password
            </button>
            @if (session('status') === 'password-updated')
                <span class="small text-success"><x-ui.icon name="check-circle" class="me-1" />Password updated.</span>
            @endif
        </div>
    </form>
</section>
