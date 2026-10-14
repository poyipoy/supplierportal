<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.auth-splash-bootstrap', ['authSplashLayout' => 'guest', 'authSplashReadiness' => 'native-document'])
        @include('partials.i18n-bootstrap')
        <meta name="robots" content="noindex, nofollow">

        <title>@yield('title', config('app.name'))</title>

        <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">
        <link href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.2.8/latin.css" integrity="sha384-GjfJOJUB+Pa/uD6sSvglxeY3WGudhaBNhoZpfIKiYMJOFCaZPWWZbDMOJW4oMKg2" crossorigin="anonymous" rel="stylesheet">

        @include('partials.loader-logo')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="tw-font-sans tw-text-on-surface tw-antialiased tw-bg-background">
        <x-ui.auth-splash :error="isset($exception)" />
        <div class="tw-flex tw-min-h-screen tw-flex-col">
            <header class="tw-border-b tw-border-outline-variant tw-bg-surface">
                <div class="tw-mx-auto tw-flex tw-max-w-lg tw-items-center tw-gap-3 tw-px-4 tw-py-3">
                    <img src="{{ asset('assets/images/logo-adasi-full.png') }}" alt="{{ config('app.name') }}" class="tw-h-11 tw-w-auto tw-object-contain">
                </div>
            </header>

            <main class="tw-flex-1 tw-px-4 tw-py-8 sm:tw-py-12">
                @yield('content')
            </main>
        </div>
        @if (! isset($exception) && request()->attributes->get('ui.auth_splash_rendered'))
            <template data-auth-splash-layout-ready="guest" data-readiness="native-document"></template>
        @endif
    </body>
</html>
