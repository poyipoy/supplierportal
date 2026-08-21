<section>
    <form id="send-verification" method="POST" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="POST" action="{{ route('profile.update') }}">
        @csrf
        @method('PATCH')

        <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
            <div class="tw-grid tw-gap-1.5">
                <label for="name" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Name</label>
                <input id="name" name="name" type="text"
                    class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('name') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required autofocus>
                @error('name')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="tw-grid tw-gap-1.5">
                <label for="email" class="tw-text-ui-sm tw-font-medium tw-text-on-surface">Email</label>
                <input id="email" name="email" type="email"
                    class="tw-h-10 tw-w-full tw-rounded-ui-sm tw-border tw-bg-surface tw-px-3 tw-text-ui-sm tw-text-on-surface focus:tw-border-primary focus:tw-ring-2 focus:tw-ring-primary {{ $errors->has('email') ? 'tw-border-error' : 'tw-border-outline-variant' }}"
                    value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="username" required>
                @error('email')<p class="tw-m-0 tw-text-ui-xs tw-font-medium tw-text-error" role="alert">{{ $message }}</p>@enderror

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <x-ui.alert tone="warning" class="tw-mt-2">
                        <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-2">
                            <span class="tw-text-ui-sm">Your email address has not been verified.</span>
                            <button form="send-verification" class="tw-text-ui-xs tw-font-semibold tw-text-primary tw-underline" type="submit">Send verification email</button>
                        </div>
                    </x-ui.alert>
                @endif
            </div>
        </div>

        <div class="tw-mt-5 tw-flex tw-items-center tw-gap-3">
            <button type="submit" class="ui-focus-ring ui-motion tw-inline-flex tw-h-10 tw-items-center tw-gap-2 tw-rounded-ui-sm tw-border-0 tw-bg-primary tw-px-4 tw-text-ui-sm tw-font-semibold tw-text-primary-foreground hover:tw-brightness-95">
                <x-ui.icon name="check" />Save Profile
            </button>
        </div>
    </form>
</section>
