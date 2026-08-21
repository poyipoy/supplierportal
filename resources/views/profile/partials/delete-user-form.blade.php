<section class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
    <div>
        <h6 class="text-danger mb-1">Delete Account</h6>
        <p class="text-muted small mb-0">This permanently deletes your account and associated access. This action cannot be undone.</p>
    </div>

    <button type="button" class="btn btn-outline-danger flex-shrink-0" data-bs-toggle="modal" data-bs-target="#confirmUserDeletionModal">
        <x-ui.icon name="trash-2" class="me-1" />Delete Account
    </button>

    <div class="modal fade" id="confirmUserDeletionModal" tabindex="-1" aria-labelledby="confirmUserDeletionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('profile.destroy') }}">
                    @csrf
                    @method('DELETE')

                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmUserDeletionModalLabel">Delete your account?</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">Enter your current password to permanently delete this account.</p>
                        <label for="delete_account_password" class="form-label fw-medium">Current Password</label>
                        <input id="delete_account_password" name="password" type="password"
                            class="form-control @error('password', 'userDeletion') is-invalid @enderror"
                            autocomplete="current-password" maxlength="255" required>
                        @error('password', 'userDeletion')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <x-ui.icon name="trash-2" class="me-1" />Delete Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

@if ($errors->userDeletion->isNotEmpty())
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modalElement = document.getElementById('confirmUserDeletionModal');

                if (modalElement && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            });
        </script>
    @endpush
@endif
