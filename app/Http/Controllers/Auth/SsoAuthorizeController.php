<?php

namespace App\Http\Controllers\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ShareAuthSplashState;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SsoAuthorizeController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless(config('sso.enabled'), 404);

        $query = $request->query();
        $clientId = $query['client_id'] ?? null;
        $redirectUri = $query['redirect_uri'] ?? null;
        $challenge = $query['code_challenge'] ?? null;
        $state = $query['state'] ?? null;
        $client = is_string($clientId) ? app(ClientRepository::class)->findActive($clientId) : null;

        if (! $client || ! is_string($redirectUri) || ! in_array($redirectUri, $client->redirect_uris, true)
            || ($query['response_type'] ?? null) !== 'code'
            || ($query['code_challenge_method'] ?? null) !== 'S256'
            || ! is_string($challenge) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge) !== 1
            || ! is_string($state) || $state === '' || strlen($state) > 128
            || (isset($query['scope']) && ! is_string($query['scope']))
            || isset($query['prompt'])) {
            return $this->invalid($request);
        }

        try {
            $psrRequest = app(ServerRequestInterface::class);
            app(AuthorizationServer::class)->validateAuthorizationRequest($psrRequest);
        } catch (Throwable) {
            // OAuth exceptions can contain client-controlled protocol material.
            return $this->invalid($request);
        }

        if (! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());
            $request->attributes->set('sso.authorization_valid', true);
            app(ShareAuthSplashState::class)->shareLoginPage($request);

            return response(app()->call([app(AuthenticatedSessionController::class), 'create']));
        }

        if (! $request->user()->isAuthenticationEligible()) {
            return $this->invalid($request);
        }

        try {
            $response = app()->call([app(AuthorizationController::class), 'authorize'], [
                'psrRequest' => $psrRequest,
                'request' => $request,
                'psrResponse' => app(ResponseInterface::class),
                'viewResponse' => app(AuthorizationViewResponse::class),
            ]);
        } catch (Throwable) {
            return $this->invalid($request);
        }

        return $response instanceof AuthorizationViewResponse ? $response->toResponse($request) : $response;
    }

    public function approve(Request $request): Response
    {
        return $this->consent($request, ApproveAuthorizationController::class, 'approve');
    }

    public function deny(Request $request): Response
    {
        return $this->consent($request, DenyAuthorizationController::class, 'deny');
    }

    private function consent(Request $request, string $controller, string $method): Response
    {
        abort_unless(config('sso.enabled'), 404);
        if (! $request->user()->isAuthenticationEligible()) {
            return $this->invalid($request);
        }
        try {
            return app()->call([app($controller), $method]);
        } catch (Throwable) {
            return $this->invalid($request);
        }
    }

    private function invalid(Request $request): Response
    {
        event(new AuthSecurityEvent('sso_invalid_authorization_request', $request->user(), metadata: [
            'client_id' => (string) config('sso.client_id'),
            'reason' => 'invalid_request',
        ]));

        return response()->view('auth.sso-error', ['message' => __('auth.sso.invalid_request')], 400);
    }
}
