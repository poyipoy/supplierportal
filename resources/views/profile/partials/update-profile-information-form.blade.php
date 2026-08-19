<section>
    <p class="text-muted small mb-4">Keep this information accurate so Purchasing, Suppliers, and QC can identify your account correctly.</p>

    <form id="send-verification" method="POST" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="POST" action="{{ route('profile.update') }}">
        @csrf
        @method('PATCH')

        <div class="row g-3">
            <div class="col-12">
                <label for="name" class="form-label fw-medium">Name</label>
                <input id="name" name="name" type="text"
                    class="form-control @error('name') is-invalid @enderror"
                    value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required autofocus>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <label for="email" class="form-label fw-medium">Email</label>
                <input id="email" name="email" type="email"
                    class="form-control @error('email') is-invalid @enderror"
                    value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="username" required>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="alert alert-warning py-2 px-3 small mt-3 mb-0">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span>Your email address has not been verified.</span>
                            <button form="send-verification" class="btn btn-sm btn-outline-warning" type="submit">
                                Send verification email
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="d-flex align-items-center gap-3 mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2 me-1"></i>Save Profile
            </button>
            @if (session('status') === 'profile-updated')
                <span class="small text-success"><i class="bi bi-check-circle me-1"></i>Profile saved.</span>
            @endif
        </div>
    </form>
</section>
