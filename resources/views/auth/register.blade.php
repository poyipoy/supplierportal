<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="'Name'" />
            <x-text-input id="name" class="tw-mt-1 tw-block tw-w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="tw-mt-2" />
        </div>

        <!-- Email Address -->
        <div class="tw-mt-4">
            <x-input-label for="email" :value="'Email'" />
            <x-text-input id="email" class="tw-mt-1 tw-block tw-w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="tw-mt-2" />
        </div>

        <!-- Password -->
        <div class="tw-mt-4">
            <x-input-label for="password" :value="'Password'" />

            <x-text-input id="password" class="tw-mt-1 tw-block tw-w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" minlength="12" maxlength="255" />

            <x-input-error :messages="$errors->get('password')" class="tw-mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="tw-mt-4">
            <x-input-label for="password_confirmation" :value="'Confirm Password'" />

            <x-text-input id="password_confirmation" class="tw-mt-1 tw-block tw-w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" minlength="12" maxlength="255" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="tw-mt-2" />
        </div>

        <div class="tw-mt-4 tw-flex tw-items-center tw-justify-end">
            <a class="tw-rounded-ui-sm tw-text-ui-sm tw-text-on-surface-variant tw-underline hover:tw-text-on-surface focus-visible:tw-outline-none focus-visible:tw-ring-2 focus-visible:tw-ring-primary focus-visible:tw-ring-offset-2" href="{{ route('login') }}">
                Already registered?
            </a>

            <x-primary-button class="tw-ms-4">
                Register
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
