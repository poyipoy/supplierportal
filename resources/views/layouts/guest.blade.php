<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- ADASI Alert -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
        <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="tw-font-sans tw-text-on-surface tw-antialiased">
        <div class="tw-flex tw-min-h-screen tw-flex-col tw-items-center tw-bg-background tw-pt-6 sm:tw-justify-center sm:tw-pt-0">
            <div>
                <a href="/">
                    <x-application-logo class="tw-h-20 tw-w-20 tw-fill-current tw-text-on-surface-variant" />
                </a>
            </div>

            <div class="tw-mt-6 tw-w-full tw-overflow-hidden tw-bg-surface tw-px-6 tw-py-4 tw-shadow-ui-1 sm:tw-max-w-md sm:tw-rounded-ui-md">
                @include('partials.alerts')
                {{ $slot }}
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
        <script src="{{ asset('assets/js/adasi-alert.js') }}"></script>
    </body>
</html>
