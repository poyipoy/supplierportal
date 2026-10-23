<?php

use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\PortalLegacyPathController;
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
use Laravel\Passport\Exceptions\OAuthServerException;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

                try {
                    $response = app()->call([app(AccessTokenController::class), 'issueToken']);
                } catch (OAuthServerException $exception) {
                    $response = $exception->getResponse();
                } catch (Throwable) {
                    // Never render/report raw protocol exceptions with request secrets.
                    $response = response()->json(['error' => 'server_error'], 503);
                }
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');

                return $response;
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
        // A GET fallback must not turn unknown mutating paths into 405 responses.
        $exceptions->map(function (MethodNotAllowedHttpException $exception) {
            $request = request();
            $allowed = PortalLegacyPathController::allowedMethods($request);
            if ($allowed === []) {
                return new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
            }

            return new MethodNotAllowedHttpException($allowed, sprintf(
                'The %s method is not supported for route %s. Supported methods: %s.',
                $request->method(), $request->path(), implode(', ', $allowed),
            ));
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson()
                || $request->routeIs('notifications.unread-count', 'conversations.unread-count', 'conversations.messages.latest', 'exports.status')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // A business-rule refusal carries a translated, user-safe message, so it
        // is feedback rather than a server error. Only this marker is mapped;
        // every other exception (database, filesystem, bugs) stays generic.
        $exceptions->dontReport(BusinessRuleViolation::class);
        $exceptions->render(function (BusinessRuleViolation $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                ->with('error', $exception->getMessage());
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return RateLimitResponse::warningRedirect($request, $exception->getHeaders());
        });
    })->create();
