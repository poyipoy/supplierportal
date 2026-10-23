<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.auth-splash-bootstrap', ['authSplashLayout' => 'guest', 'authSplashReadiness' => 'native-document'])
        @include('partials.i18n-bootstrap')
        <meta name="robots" content="noindex, nofollow">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">

        <!-- Fonts -->
        <link href="https://cdn.jsdelivr.net/npm/@fontsource/figtree@5.2.8/latin.css" integrity="sha384-Oe7jEc47knzAwONBEoGYXy+/zqUcXhdrULzJzUWx3sn+jP6RHgC5cRlcGbnvI3p1" crossorigin="anonymous" rel="stylesheet" />

        <!-- ADASI Alert -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" integrity="sha384-e9JoBUb50niLuTodlxX3NLZZfrt9fQkX5bihGXOGWD/7QFJoXEH37S2df8UA2ehO" crossorigin="anonymous">
        <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">

        <!-- Scripts -->
        @include('partials.loader-logo')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="tw:font-sans tw:text-on-surface tw:antialiased">
        <x-ui.auth-splash :error="isset($exception)" />
        <div class="tw:flex tw:min-h-screen tw:flex-col tw:items-center tw:bg-background tw:pt-6 tw:sm:justify-center tw:sm:pt-0">
            <div>
                <a href="/">
                    <x-application-logo class="tw:h-20 tw:w-20 tw:fill-current tw:text-on-surface-variant" />
                </a>
            </div>

            <div class="tw:mt-6 tw:w-full tw:overflow-hidden tw:bg-surface tw:px-6 tw:py-4 tw:shadow-ui-1 tw:sm:max-w-md tw:sm:rounded-ui-md">
                @include('partials.alerts')
                {{ $slot ?? '' }}
                @yield('content')
            </div>
        </div>
        <x-ui.toast-container />
        @if (! isset($exception) && request()->attributes->get('ui.auth_splash_rendered'))
            <template data-auth-splash-layout-ready="guest" data-readiness="native-document"></template>
        @endif
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js" integrity="sha384-VNUenFzuMm/yuCawx3aXQgp6+Z5QL6nRMkX1fNFfqPzI1fdaooshPNCHt/Llz/bJ" crossorigin="anonymous"></script>
        <script src="{{ asset('assets/js/adasi-alert.js') }}"></script>
    </body>
</html>
