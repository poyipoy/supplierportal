<section>
    @if ($user->hasTwoFactorAuthentication())
        <div class="alert alert-success d-flex align-items-start gap-2 small mb-4" role="status">
            <i class="bi bi-shield-check fs-5"></i>
            <div>
                <div class="fw-semibold">Two-factor authentication is enabled.</div>
                <div>Use your authenticator app or a recovery code when you sign in.</div>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.two-factor.recovery-codes') }}" class="mb-4">
            @csrf
            <button type="submit" class="btn btn-outline-primary">
                <i class="bi bi-arrow-repeat me-1"></i>Regenerate Recovery Codes
            </button>
        </form>

        <hr class="my-4">

        <p class="small text-muted mb-3">To disable two-factor authentication, verify an authenticator or recovery code.</p>
        <form method="POST" action="{{ route('profile.two-factor.destroy') }}">
            @csrf
            @method('DELETE')

            <div class="mb-3">
                <label for="disable_two_factor_code" class="form-label fw-medium">Authenticator or Recovery Code</label>
                <input id="disable_two_factor_code" name="code" type="text"
                    class="form-control font-monospace @error('code') is-invalid @enderror"
                    autocomplete="one-time-code" maxlength="32" required>
                @error('code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-shield-x me-1"></i>Disable Two-Factor Authentication
            </button>
        </form>
    @else
        <div class="alert alert-light border d-flex align-items-start gap-2 small mb-4">
            <i class="bi bi-shield-lock fs-5 text-primary"></i>
            <div>
                <div class="fw-semibold">Two-factor authentication is optional.</div>
                <div class="text-muted">Add an authenticator code to protect this account beyond its password.</div>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.two-factor.start') }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-qr-code-scan me-1"></i>Set Up Two-Factor Authentication
            </button>
        </form>
    @endif
</section>
