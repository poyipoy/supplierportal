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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="tw-m-0 tw-min-h-screen tw-bg-surface tw-font-sans tw-text-on-surface tw-antialiased">
    <main class="tw-min-h-screen tw-w-full tw-grid lg:tw-grid-cols-2">
        <!-- Left Column: 50% Full-bleed Brand Hero (100vh) -->
        <aside class="tw-relative tw-hidden lg:tw-flex lg:tw-flex-col lg:tw-justify-between tw-p-10 xl:tw-p-16 tw-text-white tw-overflow-hidden" aria-label="ADASI Supplier Portal information">
            <!-- Background Image with deep slate gradient overlay -->
            <div class="tw-absolute tw-inset-0 tw-z-0">
                <img src="{{ asset('assets/images/adasi-login-bg.jpg') }}" alt="" class="tw-h-full tw-w-full tw-object-cover">
                <div class="tw-absolute tw-inset-0 tw-bg-[#0B1C30]/85 tw-mix-blend-multiply"></div>
                <div class="tw-absolute tw-inset-0 tw-bg-gradient-to-t tw-from-[#0B1C30] tw-via-transparent tw-to-transparent tw-opacity-90"></div>
            </div>

            <!-- Content on top of overlay -->
            <div class="tw-relative tw-z-10 tw-flex tw-flex-col tw-h-full tw-justify-between">
                <!-- Top Brand & Badge -->
                <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center tw-justify-between tw-gap-4">
                    <div class="tw-inline-flex tw-items-center tw-gap-3">
                        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="ADASI Logo" class="tw-h-11 tw-w-auto">
                        <div>
                            <div class="tw-text-ui-lg tw-font-bold tw-tracking-wide tw-text-white">ASTRA DAIDO STEEL INDONESIA</div>
                            <div class="tw-text-ui-xs tw-text-slate-300">Supplier Portal</div>
                        </div>
                    </div>
                    <div class="tw-inline-flex tw-items-center tw-gap-2 tw-rounded-full tw-bg-emerald-500/20 tw-border tw-border-emerald-500/30 tw-px-3.5 tw-py-1.5 tw-text-emerald-300 tw-backdrop-blur-sm">
                        <x-ui.icon name="shield-lock" size="sm" />
                        <span class="tw-text-[11px] tw-font-bold tw-uppercase tw-tracking-widest">Secure Procurement</span>
                    </div>
                </div>

                <!-- Middle Headline & Subtext -->
                <div class="tw-my-12 lg:tw-my-auto tw-max-w-xl">
                    <h1 class="tw-m-0 tw-text-3xl lg:tw-text-4xl xl:tw-text-[46px] tw-font-bold tw-leading-tight tw-tracking-tight tw-text-white">
                        Streamline every step of procurement.
                    </h1>
                    <p class="tw-m-0 tw-mt-5 tw-text-ui-base lg:tw-text-ui-lg tw-leading-relaxed tw-text-slate-200/90 tw-max-w-lg">
                        Manage purchasing activities, supplier responses, and order progress through one integrated portal.
                    </p>
                </div>

                <!-- Bottom Footnote -->
                <div>
                    <span class="tw-text-ui-xs tw-font-semibold tw-tracking-wider tw-text-slate-400 uppercase">
                        PT. ASTRA DAIDO STEEL INDONESIA
                    </span>
                </div>
            </div>
        </aside>

        <!-- Right Column: 50% Full-height Authentication Section (100vh) -->
        <section class="tw-w-full tw-flex tw-min-h-screen tw-flex-col tw-items-center tw-justify-center tw-p-6 sm:tw-p-10 lg:tw-p-12 xl:tw-p-16 tw-bg-[#F8FAFC]">
            <div class="tw-w-full tw-max-w-md tw-flex tw-flex-col tw-gap-6">
                <!-- Mobile brand fallback -->
                <a href="{{ route('login') }}" class="ui-focus-ring tw-inline-flex tw-items-center tw-justify-center tw-gap-3 tw-rounded-ui-sm tw-text-on-surface tw-no-underline lg:tw-hidden tw-mb-2">
                    <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" class="tw-h-10 tw-w-auto">
                    <span class="tw-text-start">
                        <span class="tw-block tw-font-bold tw-text-primary">ADASI</span>
                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">Supplier Portal</span>
                    </span>
                </a>

                <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-white tw-p-6 tw-shadow-ui-1 sm:tw-p-8">
                    @include('partials.alerts')
                    @yield('content')
                </div>

                <div class="tw-text-center tw-mt-2">
                    <span class="tw-text-[12px] tw-tracking-wider tw-uppercase tw-text-on-surface-variant/70">
                        &copy; {{ now()->year }} PT. Astra Daido Steel Indonesia
                    </span>
                </div>
            </div>
        </section>
    </main>

    <x-ui.toast-container />

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script src="{{ asset('assets/js/adasi-alert.js') }}"></script>
    @yield('scripts')
    @stack('scripts')
</body>
</html>
