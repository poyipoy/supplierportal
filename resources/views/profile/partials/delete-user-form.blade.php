<section class="tw-flex tw-flex-col lg:tw-flex-row tw-items-start lg:tw-items-center tw-justify-between tw-gap-4">
    <div>
        <h6 class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-error">Delete Account</h6>
        <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">This permanently deletes your account and associated access. This action cannot be undone.</p>
    </div>

    <button type="button" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-shrink-0 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-error/60 tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-medium tw-text-error hover:tw-bg-error/5" data-bs-toggle="modal" data-bs-target="#confirmUserDeletionModal">
        <x-ui.icon name="trash-2" />Delete Account
    </button>

    <div class="modal fade" id="confirmUserDeletionModal" tabindex="-1" aria-labelledby="confirmUserDeletionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content tw-border tw-border-outline-variant tw-rounded-ui-sm tw-overflow-hidden">
                <form method="POST" action="{{ route('profile.destroy') }}">
                    @csrf
                    @method('DELETE')

                    <div class="tw-border-b tw-border-outline-variant tw-px-5 tw-py-4">
                        <h5 class="tw-m-0 tw-text-ui-base tw-font-semibold" id="confirmUserDeletionModalLabel">Delete your account?</h5>
                    </div>
                    <div class="tw-p-5">
                        <p class="tw-m-0 tw-mb-4 tw-text-ui-sm tw-text-on-surface-variant">Enter your current password to permanently delete this account.</p>
                        <div class="tw-grid tw-gap-1.5">
                            <label for="delete_account_password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Current password</label>
                            <input id="delete_account_password" name="password" type="password"
                                class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->userDeletion->has('password') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                                autocomplete="current-password" maxlength="255" required>
                            @error('password', 'userDeletion')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-end tw-gap-3 tw-border-t tw-border-outline-variant tw-px-5 tw-py-3">
                        <button type="button" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-items-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-medium tw-text-on-surface hover:tw-bg-surface-container" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-error tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-error-foreground hover:tw-bg-error/90">
                            <x-ui.icon name="trash-2" />Delete Account
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
