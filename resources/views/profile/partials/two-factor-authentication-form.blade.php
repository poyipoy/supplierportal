<section>
    @if ($user->hasTwoFactorAuthentication())
        <div class="tw-flex tw-items-start tw-gap-3 tw-rounded-ui-sm tw-border tw-border-success/40 tw-bg-success/5 tw-p-3 tw-mb-4" role="status">
            <x-ui.icon name="shield-check" class="tw-shrink-0 tw-text-success tw-mt-0.5" />
            <div>
                <div class="tw-text-ui-sm tw-font-semibold">{{ __('security.two_factor_enabled') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">{{ __('security.two_factor_enabled_help') }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.two-factor.recovery-codes') }}" class="tw-mb-5">
            @csrf
            <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-medium tw-text-on-surface hover:tw-bg-surface-container">
                <x-ui.icon name="refresh-cw" />{{ __('security.regenerate_codes') }}
            </button>
        </form>

        <div class="tw-border-t tw-border-outline-variant tw-pt-4">
            <p id="disable_two_factor_help" class="tw-m-0 tw-mb-3 tw-text-ui-xs tw-text-on-surface-variant">{{ __('security.disable_help') }}</p>
            <form method="POST" action="{{ route('profile.two-factor.destroy') }}">
                @csrf
                @method('DELETE')

                <div class="tw-grid tw-gap-1.5 tw-mb-4">
                    <label for="disable_two_factor_code" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('security.code') }}</label>
                    <input id="disable_two_factor_code" name="code" type="text"
                        class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-font-mono tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('code') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                        autocomplete="one-time-code" maxlength="32"
                        aria-describedby="disable_two_factor_help{{ $errors->has('code') ? ' disable_two_factor_code_error' : '' }}"
                        aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}" required>
                    @error('code')<p id="disable_two_factor_code_error" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-9 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border tw-border-error/60 tw-bg-transparent tw-px-3 tw-text-ui-sm tw-font-medium tw-text-error hover:tw-bg-error/5">
                    <x-ui.icon name="shield-x" />{{ __('security.disable') }}
                </button>
            </form>
        </div>
    @else
        <div class="tw-flex tw-items-start tw-gap-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface-container tw-p-3 tw-mb-4">
            <x-ui.icon name="shield-lock" class="tw-shrink-0 tw-text-primary tw-mt-0.5" />
            <div>
                <div class="tw-text-ui-sm tw-font-semibold">{{ __('security.not_enabled') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">{{ __('security.add_code') }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.two-factor.start') }}">
            @csrf
            <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-10 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95">
                <x-ui.icon name="scan-qr-code" />{{ __('security.setup') }}
            </button>
        </form>
    @endif
</section>
