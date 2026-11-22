<?php

namespace App\Http\Middleware;

use App\Support\Auth\SessionLifecycle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * Extends Laravel's StartSession so read-only background polls and passive
 * session status checks never slide the database session `last_activity`
 * timestamp or refresh the session cookie expiration.
 */
class StartPortalSession extends StartSession
{
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        $request->setLaravelSession(
            $this->startSession($request, $session)
        );

        $initialId = $session->getId();
        $initialAttributes = $session->all();

        $this->collectGarbage($session);

        $response = $next($request);

        if (SessionLifecycle::shouldSkipSessionTouch($request)
            && $session->getId() === $initialId
            && $session->all() === $initialAttributes) {
            return $response;
        }

        $this->storeCurrentUrl($request, $session);

        $this->addCookieToResponse($response, $session);

        $this->saveSession($request);

        return $response;
    }
}
