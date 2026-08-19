<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @yield('meta')
    <title>@yield('title', 'Sign In - ADASI Supplier Portal')</title>

    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="tw-m-0 tw-min-h-screen tw-bg-background tw-font-sans tw-text-on-surface tw-antialiased">
    <main class="tw-grid tw-min-h-screen lg:tw-grid-cols-[minmax(18rem,0.8fr)_minmax(30rem,1.2fr)]">
        <aside class="tw-hidden tw-border-e tw-border-outline-variant tw-bg-surface-low tw-p-10 lg:tw-flex lg:tw-flex-col lg:tw-justify-between" aria-label="ADASI Supplier Portal information">
            <div>
                <div class="tw-inline-flex tw-items-center tw-gap-3">
                    <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" class="tw-h-14 tw-w-auto">
                    <div>
                        <div class="tw-text-ui-lg tw-font-semibold tw-text-primary">ADASI</div>
                        <div class="tw-text-ui-xs tw-text-on-surface-variant">Supplier Portal</div>
                    </div>
                </div>
                <div class="tw-mt-16 tw-max-w-md">
                    <p class="tw-m-0 tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-primary">Secure procurement workspace</p>
                    <h1 class="tw-m-0 tw-mt-3 tw-text-ui-3xl tw-font-semibold tw-tracking-tight">One focused place for import-material collaboration.</h1>
                    <p class="tw-m-0 tw-mt-4 tw-text-ui-sm tw-leading-6 tw-text-on-surface-variant">Access is protected by role-based permissions, authentication controls, and supplier data isolation.</p>
                </div>
            </div>
            <p class="tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">PT. Astra Daido Steel Indonesia</p>
        </aside>

        <section class="tw-flex tw-min-h-screen tw-items-center tw-justify-center tw-p-4 sm:tw-p-8 lg:tw-p-12">
            <div class="tw-w-full tw-max-w-lg">
                <a href="{{ route('login') }}" class="ui-focus-ring tw-mb-5 tw-inline-flex tw-items-center tw-gap-3 tw-rounded-ui-sm tw-text-on-surface tw-no-underline lg:tw-hidden">
                    <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" class="tw-h-11 tw-w-auto">
                    <span><span class="tw-block tw-font-semibold tw-text-primary">ADASI</span><span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">Supplier Portal</span></span>
                </a>

                <div class="tw-rounded-ui-lg tw-border tw-border-outline-variant tw-bg-surface tw-p-5 tw-shadow-ui-2 sm:tw-p-8">
                    @include('partials.alerts')
                    @yield('content')
                </div>

                <p class="tw-m-0 tw-mt-5 tw-text-center tw-text-ui-xs tw-text-on-surface-variant">&copy; {{ now()->year }} PT. Astra Daido Steel Indonesia</p>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="{{ asset('assets/js/adasi-alert.js') }}"></script>
    @yield('scripts')
    @stack('scripts')
</body>
</html>
