<section>

    <form method="POST" action="{{ route('profile.update') }}" data-track-unsaved="true">
        @csrf
        @method('PATCH')

        <div class="tw-grid tw-gap-4 md:tw-max-w-lg">
            <div class="tw-grid tw-gap-1.5">
                <label for="name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">{{ __('profile.display_name') }}</label>
                <input id="name" name="name" type="text"
                    class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('name') ? 'tw-border-error' : 'tw-border-outline-strong' }}"
                    value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required
                    aria-describedby="profile-name-help{{ $errors->has('name') ? ' profile-name-error' : '' }}"
                    aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}">
                @error('name')<p id="profile-name-error" class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="tw-mt-5 tw-flex tw-items-center tw-gap-3">
            <x-ui.button type="submit">
                <x-slot:leading><x-ui.icon name="check" /></x-slot:leading>
                {{ __('common.review.save_profile') }}
            </x-ui.button>
        </div>
    </form>
</section>
