@props(['error' => false])
@php
    $authSplash = request()->attributes->get('ui.auth_splash');
    $authSplashCanRender = ! $error && ! isset($exception)
        && config('ui.auth_splash.enabled', false)
        && request()->attributes->get('ui.auth_splash_bootstrap_available') === true
        && is_array($authSplash)
        && in_array($authSplash['reason'] ?? null, ['login-page', 'post-login', 'post-logout'], true);
@endphp
@if ($authSplashCanRender)
    @php request()->attributes->set('ui.auth_splash_rendered', true); @endphp
    <div class="auth-splash" data-auth-splash data-splash-reason="{{ $authSplash['reason'] }}" hidden aria-hidden="true">
        <div class="auth-splash__panel" tabindex="-1" aria-describedby="auth-splash-status">
            <div class="auth-splash__wordmark" aria-hidden="true">
                @foreach (str_split('ADASI') as $letter)
                    <span class="auth-splash__letter">{{ $letter }}</span>
                @endforeach
            </div>
            <div class="auth-splash__surface-plane" aria-hidden="true"></div>
            <span id="auth-splash-status" class="auth-splash__sr-only" data-auth-splash-status role="status" aria-live="polite" aria-atomic="true">{{ __('auth.splash.loading') }}</span>
        </div>
        <button class="auth-splash__skip" data-auth-splash-skip type="button" hidden disabled>{{ __('auth.splash.skip') }}</button>
    </div>
@endif
