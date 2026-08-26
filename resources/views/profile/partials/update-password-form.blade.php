<section>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        @method('PUT')

        <div class="tw-grid tw-gap-4">
            <div class="tw-grid tw-gap-1.5">
                <label for="update_password_current_password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Current password</label>
                <input id="update_password_current_password" name="current_password" type="password"
                    class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->updatePassword->has('current_password') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                    autocomplete="current-password" maxlength="255" required>
                @error('current_password', 'updatePassword')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                <div class="tw-grid tw-gap-1.5">
                    <label for="update_password_password" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">New password</label>
                    <input id="update_password_password" name="password" type="password"
                        class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->updatePassword->has('password') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                        autocomplete="new-password" minlength="12" maxlength="255" required>
                    @error('password', 'updatePassword')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="tw-grid tw-gap-1.5">
                    <label for="update_password_password_confirmation" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Confirm new password</label>
                    <input id="update_password_password_confirmation" name="password_confirmation" type="password"
                        class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-border-outline-strong tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary"
                        autocomplete="new-password" minlength="12" maxlength="255" required>
                </div>
            </div>
        </div>

        <div class="tw-mt-5 tw-flex tw-items-center tw-gap-3">
            <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-10 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95">
                <x-ui.icon name="key" />Update Password
            </button>
        </div>
    </form>
</section>
