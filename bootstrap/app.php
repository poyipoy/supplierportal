<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\DecodeHashids;
use App\Http\Middleware\EnforceAuthSessionSecurity;
use App\Http\Middleware\EnsurePasswordConfirmation;
use App\Http\Middleware\EnsurePendingTwoFactorChallenge;
use App\Http\Middleware\NoStoreResponse;
use App\Http\Middleware\RememberPurchasingListUrl;
use App\Http\Middleware\RoleMiddleware;
use App\Support\RateLimitResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
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
            DecodeHashids::class,
            EnforceAuthSessionSecurity::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'password.confirm' => EnsurePasswordConfirmation::class,
            'purchasing.navigation' => RememberPurchasingListUrl::class,
            'mfa.pending' => EnsurePendingTwoFactorChallenge::class,
            'no-store' => NoStoreResponse::class,
        ]);

        $middleware->redirectUsersTo(function () {
            if (auth()->check()) {
                return match (auth()->user()->role) {
                    'admin' => route('admin.dashboard', absolute: false),
                    'purchasing' => route('purchasing.dashboard', absolute: false),
                    'supplier' => route('supplier.dashboard', absolute: false),
                    'qc' => route('qc.dashboard', absolute: false),
                    default => '/',
                };
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return RateLimitResponse::warningRedirect($request, $exception->getHeaders());
        });
    })->create();
