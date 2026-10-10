<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    @yield('meta')
    <title>@yield('title', __('auth.login.title'))</title>

    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="{{ asset('assets/css/adasi-alert.css') }}">
    @include('partials.i18n-bootstrap')
    @include('partials.loader-logo')
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .auth-shell {
            display: grid;
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
        }
        @media (min-width: 1024px) {
            .auth-shell { grid-template-columns: 1fr 1fr; }
        }

        /* ── Industrial Image Panel ── */
        .auth-brand-panel {
            display: none;
            position: relative;
            overflow: hidden;
            background: var(--md-ref-secondary-950);
        }
        @media (min-width: 1024px) {
            .auth-brand-panel { display: flex; flex-direction: column; justify-content: space-between; }
        }

        .auth-brand-panel__image {
            position: absolute;
            inset: 0;
            z-index: 0;
        }
        .auth-brand-panel__image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            pointer-events: none;
            user-select: none;
            -webkit-user-drag: none;
        }
        .auth-brand-panel__overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(
                180deg,
                rgba(var(--md-scrim-rgb), 0.72) 0%,
                rgba(var(--md-scrim-rgb), 0.60) 40%,
                rgba(var(--md-scrim-rgb), 0.82) 100%
            );
        }

        .auth-brand-panel__content {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            padding: 2rem 2.5rem;
        }
        @media (min-width: 1280px) {
            .auth-brand-panel__content { padding: 2.5rem 3rem; }
        }

        .auth-brand-logo {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
        }
        .auth-brand-logo img {
            height: 2rem;
            width: auto;
            flex-shrink: 0;
            pointer-events: none;
            user-select: none;
            -webkit-user-drag: none;
        }
        .auth-brand-logo__text {
            font-size: var(--ui-font-size-sm);
            font-weight: 700;
            letter-spacing: 0.04em;
            color: rgba(var(--md-on-primary-rgb), 0.92);
            line-height: 1.3;
        }
        .auth-brand-logo__sub {
            display: block;
            font-size: var(--ui-font-size-xs);
            font-weight: 500;
            letter-spacing: 0.06em;
            color: rgba(var(--md-on-primary-rgb), 0.55);
            margin-top: 0.125rem;
        }

        .auth-brand-headline {
            max-width: 26rem;
            margin: auto 0;
            padding: 2rem 0;
        }
        .auth-brand-headline h1, .auth-brand-headline .auth-brand-title {
            margin: 0;
            font-size: var(--ui-font-size-2xl);
            font-weight: 700;
            line-height: 1.28;
            letter-spacing: -0.01em;
            color: var(--md-on-primary);
        }
        .auth-brand-headline p {
            margin: 0.875rem 0 0;
            font-size: var(--ui-font-size-sm);
            line-height: 1.6;
            color: rgba(var(--md-on-primary-rgb), 0.65);
            max-width: 22rem;
        }

        .auth-brand-footer {
            font-size: var(--ui-font-size-xs);
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(var(--md-on-primary-rgb), 0.38);
        }

        /* ── Auth Form Panel ── */
        .auth-form-panel {
            display: flex;
            flex-direction: column;
            min-width: 0;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            min-height: 100dvh;
            padding: 2rem 1.5rem;
            background: var(--md-background);
        }
        @media (min-width: 640px) {
            .auth-form-panel { padding: 2.5rem; }
        }
        @media (min-width: 1024px) {
            .auth-form-panel { min-height: unset; padding: 3rem 3.5rem; }
        }

        .auth-form-surface {
            width: 100%;
            max-width: 26rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        /* Mobile brand fallback */
        .auth-mobile-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            text-decoration: none;
            color: var(--md-on-surface);
            margin-bottom: 0.25rem;
        }
        .auth-mobile-brand img {
            height: 2.25rem;
            width: auto;
            pointer-events: none;
            user-select: none;
            -webkit-user-drag: none;
        }
        .auth-mobile-brand__name {
            font-weight: 700;
            font-size: var(--ui-font-size-base);
            color: var(--md-primary);
        }
        .auth-mobile-brand__sub {
            display: block;
            font-size: var(--ui-font-size-xs);
            color: var(--md-on-surface-variant);
        }
        @media (min-width: 1024px) {
            .auth-mobile-brand { display: none; }
        }

        /* Auth card — clean, flat, no glass */
        .auth-card {
            background: var(--md-surface);
            border: 1px solid var(--md-outline-variant);
            border-radius: var(--md-shape-sm);
            padding: 1.75rem;
        }
        @media (min-width: 640px) {
            .auth-card { padding: 2rem 2.25rem; }
        }

        .auth-footer-copy {
            text-align: center;
            font-size: var(--ui-font-size-xs);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--md-on-surface-variant);
            margin-top: 0.25rem;
        }
        @media (min-width: 768px) {
            .reg-tax-grid {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                grid-template-rows: auto auto auto !important;
                column-gap: 1rem !important;
                row-gap: 0.375rem !important;
                align-items: start !important;
            }
            .reg-tax-col {
                display: grid !important;
                grid-row: span 3 !important;
                grid-template-rows: subgrid !important;
                row-gap: 0.375rem !important;
            }
            .reg-tax-header-sync {
                min-height: 48px !important;
                display: flex !important;
                align-items: flex-start !important;
            }
        }

        /* Language switcher toggle track & pills */
        .lang-toggle-track {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem;
            border-radius: var(--md-shape-full);
            background: var(--md-surface-container-high);
            border: 1px solid var(--md-outline-strong);
            box-shadow: 0 1px 2px 0 rgba(15, 23, 42, 0.05);
        }
        .lang-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            min-height: 2rem;
            border-radius: var(--md-shape-full);
            font-size: var(--ui-font-size-xs);
            font-weight: 700;
            border: 1px solid transparent;
            color: var(--md-on-surface-variant);
            background: transparent;
            cursor: pointer;
            outline: none;
            transition-property: background-color, color, border-color, box-shadow, transform;
            transition-duration: 160ms;
            transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
        }
        .lang-pill-btn.is-active {
            background: var(--md-surface) !important;
            color: var(--md-primary) !important;
            border-color: var(--md-outline) !important;
            box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.1), 0 1px 2px -1px rgba(15, 23, 42, 0.08) !important;
            cursor: default;
        }
        .lang-pill-btn:not(.is-active):hover {
            color: var(--md-on-surface);
            background: rgba(255, 255, 255, 0.65);
            border-color: rgba(203, 213, 225, 0.4);
        }
        .lang-pill-btn:active {
            transform: scale(0.96);
        }
        .lang-flag-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 14px;
            border-radius: 2.5px;
            overflow: hidden;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.15);
            flex-shrink: 0;
        }
        @media (prefers-reduced-motion: reduce) {
            .lang-pill-btn {
                transition: none !important;
                transform: none !important;
            }
            .lang-pill-btn:active {
                transform: none !important;
            }
        }
    </style>
</head>
<body class="tw-m-0 tw-min-h-screen tw-bg-surface tw-font-sans tw-text-on-surface tw-antialiased">
    <main class="auth-shell" @yield('shell-attributes')>
        @hasSection('brand-panel')
            @yield('brand-panel')
        @else
            {{-- Left Column: Industrial Image Panel --}}
            <aside class="auth-brand-panel" aria-label="{{ __('navigation.brand_info') }}">
                <div class="auth-brand-panel__image">
                    <img src="{{ asset('assets/images/adasi-login-bg.jpg') }}" alt="" loading="eager" draggable="false">
                    <div class="auth-brand-panel__overlay"></div>
                </div>

                <div class="auth-brand-panel__content">
                    <div class="auth-brand-logo">
                        <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="{{ __('common.review.logo') }}" draggable="false">
                        <div>
                            <span class="auth-brand-logo__text">ASTRA DAIDO STEEL INDONESIA</span>
                            <span class="auth-brand-logo__sub">{{ __('common.review.supplier_portal') }}</span>
                        </div>
                    </div>

                    <div class="auth-brand-headline">
                        @hasSection('page-heading')
                        <p class="auth-brand-title">{{ __('navigation.brand_heading') }}</p>
                        @else
                        <h1>{{ __('navigation.brand_heading') }}</h1>
                        @endif
                        <p>{{ __('navigation.brand_description') }}</p>
                    </div>

                    <div class="auth-brand-footer">
                        PT. Astra Daido Steel Indonesia
                    </div>
                </div>
            </aside>
        @endif

        {{-- Right Column: Authentication Form --}}
        <section class="auth-form-panel">
            <div class="auth-form-surface">
                <a href="{{ route('login') }}" class="auth-mobile-brand">
                    <img src="{{ asset('assets/images/logo-adasi.png') }}" alt="" draggable="false">
                    <span>
                        <span class="auth-mobile-brand__name">ADASI</span>
                        <span class="auth-mobile-brand__sub">{{ __('common.review.supplier_portal') }}</span>
                    </span>
                </a>

                <div class="auth-card">
                    @include('partials.alerts')
                    @yield('content')
                </div>

                <div class="auth-footer-copy">
                    &copy; {{ now()->year }} PT. Astra Daido Steel Indonesia
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
