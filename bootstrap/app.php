<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\ApplyUserLocale;
use App\Http\Middleware\DecodeHashids;
use App\Http\Middleware\EnforceAuthSessionSecurity;
use App\Http\Middleware\EnforcePasswordChange;
use App\Http\Middleware\EnforceSupplierDomain;
use App\Http\Middleware\EnsurePasswordConfirmation;
use App\Http\Middleware\EnsurePendingTwoFactorChallenge;
use App\Http\Middleware\EnsureRegistrationSession;
use App\Http\Middleware\EnsureSsoSession;
use App\Http\Middleware\LimitOperationalRequests;
use App\Http\Middleware\NoStoreResponse;
use App\Http\Middleware\RememberPurchasingListUrl;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\ShareAuthSplashState;
use App\Http\Middleware\SupplierScopeMiddleware;
use App\Support\PortalContext;
use App\Support\RateLimitResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // OAuth token requests are stateless; never attach the web group here.
            Route::post('/oauth/token', function () {
                abort_unless(config('sso.enabled'), 404);

                return app()->call([app(AccessTokenController::class), 'issueToken']);
            })->middleware('throttle:60,1')->name('passport.token');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
            function (string $proxy): bool {
                [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);

                if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                    return false;
                }

                if ($prefix === null) {
                    return true;
                }

                $maximum = str_contains($address, ':') ? 128 : 32;

                return ctype_digit($prefix) && (int) $prefix <= $maximum;
            }
        ));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->authenticateSessions();

        $middleware->web(append: [
            ApplyUserLocale::class,
            ShareAuthSplashState::class,
            DecodeHashids::class,
            EnforceAuthSessionSecurity::class,
            EnsureSsoSession::class,
            EnforcePasswordChange::class,
            AddSecurityHeaders::class,
            EnforceSupplierDomain::class,
            LimitOperationalRequests::class,
        ]);
        $middleware->appendToPriorityList(StartSession::class, ApplyUserLocale::class);
        $middleware->appendToPriorityList(ApplyUserLocale::class, ShareAuthSplashState::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'supplier.scope' => SupplierScopeMiddleware::class,
            'password.confirm' => EnsurePasswordConfirmation::class,
            'purchasing.navigation' => RememberPurchasingListUrl::class,
            'mfa.pending' => EnsurePendingTwoFactorChallenge::class,
            'no-store' => NoStoreResponse::class,
            'registration.session' => EnsureRegistrationSession::class,
        ]);

        $middleware->redirectUsersTo(function () {
            if (auth()->check()) {
                return match (auth()->user()->role) {
                    'admin' => route('admin.dashboard', absolute: false),
                    'purchasing' => route('purchasing.dashboard', absolute: false),
                    'supplier', 'accounting', 'finance' => PortalContext::dashboard(auth()->user()),
                    'qc' => route('qc.dashboard', absolute: false),
                    default => '/',
                };
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson()
                || $request->is('notifications/unread-count', 'conversations/unread-count', 'conversations/*/messages/latest', 'exports/*/status')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return RateLimitResponse::warningRedirect($request, $exception->getHeaders());
        });
    })->create();
