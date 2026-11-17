@php
    $authSplash = request()->attributes->get('ui.auth_splash');
    $authSplashSources = null;
    request()->attributes->set('ui.auth_splash_bootstrap_available', false);

    if (! isset($exception) && config('ui.auth_splash.enabled', false)
        && is_array($authSplash)
        && in_array($authSplash['reason'] ?? null, ['login-page', 'post-login', 'post-logout'], true)) {
        // Fixed trusted sources only: failure must leave the existing page usable.
        try {
            $authSplashScriptPath = resource_path('js/auth-splash.js');
            $authSplashStylePath = resource_path('css/auth-splash.css');
            if (\Illuminate\Support\Facades\File::isReadable($authSplashScriptPath)
                && \Illuminate\Support\Facades\File::isReadable($authSplashStylePath)) {
                $authSplashSources = [
                    'script' => \Illuminate\Support\Facades\File::get($authSplashScriptPath),
                    'style' => \Illuminate\Support\Facades\File::get($authSplashStylePath),
                ];
                request()->attributes->set('ui.auth_splash_bootstrap_available', true);
            }
        } catch (\Throwable $authSplashSourceFailure) {
            $authSplashSources = null;
        }
    }
@endphp
@if ($authSplashSources !== null)
    <link rel="preload" as="image" href="{{ asset('assets/images/logo-adasi.gif') }}" fetchpriority="high" media="(prefers-reduced-motion: no-preference)">
    <link rel="preload" as="image" href="{{ asset('assets/images/logo-adasi-wordmark.png') }}" fetchpriority="high" media="(prefers-reduced-motion: reduce)">
    <style data-auth-splash-styles>{!! $authSplashSources['style'] !!}</style>
    <script data-auth-splash-bootstrap
        data-reason="{{ $authSplash['reason'] }}"
        data-layout="{{ $authSplashLayout }}"
        data-readiness="{{ $authSplashReadiness }}"
        data-hard-timeout="{{ (int) config('ui.auth_splash.hard_timeout_ms', 8000) }}"
        data-min-visible-ms="{{ config('ui.auth_splash.min_visible_ms', 600) }}"
        data-media-fallback-ms="{{ (int) config('ui.auth_splash.media_fallback_ms', 500) }}"
        data-slow-after="{{ (int) config('ui.auth_splash.slow_after_ms', 2000) }}"
        data-fade-ms="{{ (int) config('ui.auth_splash.fade_ms', 500) }}"
        data-cleanup-ms="{{ (int) config('ui.auth_splash.cleanup_ms', 600) }}"
    >{!! $authSplashSources['script'] !!}</script>
@endif
