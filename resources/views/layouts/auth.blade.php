<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="text-scale" content="scale">
    <meta name="color-scheme" content="light dark">
    <script>
        (() => {
            const pref = @js($preferenceFrontendPayload['theme'] ?? 'system');
            const mql = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
            const apply = () => {
                const dark = pref === 'dark' || (pref === 'system' && Boolean(mql && mql.matches));
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            };
            apply();
            if (pref === 'system' && mql && typeof mql.addEventListener === 'function') {
                mql.addEventListener('change', apply);
            }
        })();
    </script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        window.AdasiLoginUrl = @js(route('login'));
        window.AdasiSession = null;
    </script>
    @include('partials.auth-splash-bootstrap', ['authSplashLayout' => 'auth', 'authSplashReadiness' => request()->routeIs('login', 'passport.authorizations.authorize') ? 'native-login' : 'core'])
    @include('partials.i18n-bootstrap')
    <meta name="robots" content="noindex, nofollow">
    @yield('meta')
    <title>{{ request()->routeIs('login') ? 'ADASI Supplier Portal - Sign In' : 'ADASI Supplier Portal' }}</title>

    <link rel="icon" href="{{ asset('assets/images/logo-adasi.png') }}" type="image/png">
    @include('partials.loader-logo')
    @vite(['resources/css/auth.css', 'resources/js/auth.js'])
    @if(\App\Support\WorkspaceShell::enabled())
        <style>
            @view-transition {
                navigation: none;
            }
        </style>
    @endif
</head>
@php
    $isPlainLayout = \Illuminate\Support\Facades\View::hasSection('plain-layout') || isset($exception);
@endphp
<body class="tw:m-0 tw:min-h-screen tw:bg-surface tw:font-sans tw:text-on-surface tw:antialiased">
    <a href="#main-content" class="ui-skip-link">{{ __('navigation.skip') }}</a>
    <x-ui.auth-splash :error="isset($exception)" />
    <main id="main-content" tabindex="-1" class="auth-shell {{ $isPlainLayout ? 'auth-shell--plain' : '' }}" @yield('shell-attributes')>
        @if(! $isPlainLayout)
            @hasSection('brand-panel')
                @yield('brand-panel')
            @else
                {{-- Left Column: Industrial Image Panel --}}
                <aside class="auth-brand-panel" aria-label="{{ __('navigation.brand_info') }}">
                    <div class="auth-brand-panel__image">
                        <picture class="auth-brand-panel__picture">
                            <source media="(min-width: 1024px)" srcset="{{ asset('assets/images/adasi-login-bg.avif') }}" type="image/avif">
                            <source media="(min-width: 1024px)" srcset="{{ asset('assets/images/adasi-login-bg.webp') }}" type="image/webp">
                            <source media="(min-width: 1024px)" srcset="{{ asset('assets/images/adasi-login-bg.jpg') }}" type="image/jpeg">
                            <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="" width="1600" height="828" loading="eager" decoding="async" fetchpriority="high" draggable="false">
                        </picture>
                        <div class="auth-brand-panel__overlay"></div>
                    </div>

                    <div class="auth-brand-panel__content">
                        <div class="auth-brand-logo">
                            <picture>
                                <source srcset="{{ asset('assets/images/logo-adasi-sm.webp') }}" type="image/webp">
                                <img src="{{ asset('assets/images/logo-adasi-sm.png') }}" alt="{{ __('common.review.logo') }}" width="34" height="32" loading="eager" decoding="async" draggable="false">
                            </picture>
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
        @endif

        {{-- Right Column: Authentication Form --}}
        <section class="auth-form-panel">
            <div class="auth-form-surface">
                @if(! $isPlainLayout)
                    <a href="{{ route('login') }}" class="auth-mobile-brand">
                        <picture>
                            <source srcset="{{ asset('assets/images/logo-adasi-sm.webp') }}" type="image/webp">
                            <img src="{{ asset('assets/images/logo-adasi-sm.png') }}" alt="" width="39" height="36" loading="eager" decoding="async" draggable="false">
                        </picture>
                        <span>
                            <span class="auth-mobile-brand__name">ADASI</span>
                            <span class="auth-mobile-brand__sub">{{ __('common.review.supplier_portal') }}</span>
                        </span>
                    </a>

                    <x-ui.environment-tag placement="auth" class="tw:self-center" />
                @endif

                @php
                    $shouldFadeAuthCard = (bool) request()->attributes->get('ui.auth_splash_rendered')
                        || ! request()->routeIs('login', 'passport.authorizations.authorize');
                @endphp
                <div class="auth-card{{ $shouldFadeAuthCard ? ' ui-page-fade' : '' }}">
                    @include('partials.alerts')
                    @yield('content')
                </div>

                @if(! $isPlainLayout)
                    <div class="auth-footer-copy">
                        &copy; {{ now()->year }} PT. Astra Daido Steel Indonesia
                    </div>
                @endif
            </div>
        </section>
    </main>

    <x-ui.toast-container />

    @if (! isset($exception) && request()->attributes->get('ui.auth_splash_rendered') && request()->routeIs('login', 'passport.authorizations.authorize'))
        <template data-auth-splash-layout-ready="auth" data-readiness="native-login"></template>
    @endif

    <script id="adasi-auth-alert-assets" type="application/json">{!! json_encode([
        'swalCss' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css',
        'swalCssIntegrity' => 'sha384-e9JoBUb50niLuTodlxX3NLZZfrt9fQkX5bihGXOGWD/7QFJoXEH37S2df8UA2ehO',
        'alertCss' => asset('assets/css/adasi-alert.css').'?v='.filemtime(public_path('assets/css/adasi-alert.css')),
        'swalJs' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js',
        'swalJsIntegrity' => 'sha384-VNUenFzuMm/yuCawx3aXQgp6+Z5QL6nRMkX1fNFfqPzI1fdaooshPNCHt/Llz/bJ',
        'alertJs' => asset('assets/js/adasi-alert.js').'?v='.filemtime(public_path('assets/js/adasi-alert.js')),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @yield('scripts')
    @stack('scripts')
    @if (! isset($exception) && request()->attributes->get('ui.auth_splash_rendered') && ! request()->routeIs('login', 'passport.authorizations.authorize'))
        <template data-auth-splash-layout-ready="auth" data-readiness="core"></template>
    @endif
</body>
</html>
