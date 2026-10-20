<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ShareAuthSplashState
{
    private const SESSION_KEY = 'ui.auth_splash';

    public function shareLoginPage(Request $request): void
    {
        if (config('ui.auth_splash.enabled', false)
            && $request->isMethod('GET') && $this->isDocumentNavigation($request)
            && ! $request->attributes->has(self::SESSION_KEY) && $this->cleanLogin($request)) {
            $pending = $request->session()->get(self::SESSION_KEY);
            $reason = $this->validMarker($pending)
                && $this->targetPath($request, $pending['target']) === $this->targetPath($request, $request->getRequestUri())
                && $this->eligibleReason($request, $pending['reason'])
                ? $pending['reason'] : 'login-page';
            $request->attributes->set(self::SESSION_KEY, ['reason' => $reason]);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ui.auth_splash.enabled', false)) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        // Background requests must not consume a document's pending handoff.
        $document = $request->isMethod('GET') && $this->isDocumentNavigation($request);
        $pending = $request->session()->get(self::SESSION_KEY);
        $capturedId = is_array($pending) ? ($pending['id'] ?? null) : null;

        // Guest OAuth requests must be validated before offering any splash.
        $deferSsoLogin = $request->routeIs('passport.authorizations.authorize') && $request->user() === null;
        if ($document && $pending !== null && ! $deferSsoLogin) {
            if (! $this->validMarker($pending)
                || $this->targetPath($request, $pending['target'] ?? null) !== $this->targetPath($request, $request->getRequestUri())
                || ! $this->eligibleReason($request, $pending['reason'] ?? null)) {
                $request->session()->forget(self::SESSION_KEY);
                $pending = null;
            } else {
                $request->attributes->set(self::SESSION_KEY, ['reason' => $pending['reason']]);
            }
        }

        if ($document && ! $request->attributes->has(self::SESSION_KEY) && $this->cleanLogin($request)) {
            $request->attributes->set(self::SESSION_KEY, ['reason' => 'login-page']);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($document) {
                $this->forgetMatching($request, $capturedId);
            }
            $issued = $request->session()->get(self::SESSION_KEY);
            if (is_array($issued) && ($issued['id'] ?? null) !== $capturedId
                && ($issued['target'] ?? null) === null
                && $this->isIssuance($request, $issued)) {
                $this->forgetMatching($request, $issued['id'] ?? null);
            }

            throw $exception;
        }

        // Re-read after security middleware, login regeneration, and logout
        // invalidation. Never recreate captured state or modify a replacement ID.
        $current = $request->session()->get(self::SESSION_KEY);
        if (! is_array($current) || ! is_string($current['id'] ?? null)) {
            return $response;
        }

        if ($current['id'] !== $capturedId) {
            $this->bindNewMarker($request, $response, $current);

            return $response;
        }

        if (! $document || ! is_array($pending)) {
            return $response;
        }

        if ($response->isRedirection()) {
            $target = $this->targetPath($request, $response->headers->get('Location'));
            if ($target !== null) {
                $request->session()->put(self::SESSION_KEY, array_replace($current, ['target' => $target]));
            } else {
                $this->forgetMatching($request, $capturedId);
            }

            return $response;
        }

        // A final response always terminates this handoff: only an acknowledged
        // successful HTML layout can have displayed it; other responses discard it.
        $rendered = $response->getStatusCode() === 200
            && str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
            && ! $response->headers->has('Content-Disposition')
            && $request->attributes->get('ui.auth_splash_rendered') === true;
        if (! $rendered) {
            $request->attributes->remove(self::SESSION_KEY);
        }
        $this->forgetMatching($request, $capturedId);

        return $response;
    }

    private function bindNewMarker(Request $request, Response $response, array $marker): void
    {
        if (! $this->isIssuance($request, $marker)) {
            return;
        }

        $target = $response->isRedirection()
            ? $this->targetPath($request, $response->headers->get('Location'))
            : null;

        if (! $this->isDocumentNavigation($request)
            || ! $this->validMarker($marker, allowUnbound: true)
            || $target === null
            || ! $this->eligibleReason($request, $marker['reason'], issuing: true)) {
            $this->forgetMatching($request, $marker['id']);

            return;
        }

        $request->session()->put(self::SESSION_KEY, array_replace($marker, ['target' => $target]));
    }

    private function isIssuance(Request $request, array $marker): bool
    {
        return $request->isMethod('POST') && match ($marker['reason'] ?? null) {
            'post-login' => $request->routeIs('login.store', 'two-factor.challenge.store'),
            'post-logout' => $request->routeIs('logout'),
            default => false,
        };
    }

    private function isDocumentNavigation(Request $request): bool
    {
        if ($request->ajax() || $request->expectsJson() || ! $request->acceptsHtml()
            || $request->headers->has('X-Adasi-Server-Tabs')) {
            return false;
        }

        $destination = $request->headers->get('Sec-Fetch-Dest');
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return ($destination === null || $destination === 'document')
            && ($mode === null || $mode === 'navigate');
    }

    private function validMarker(mixed $marker, bool $allowUnbound = false): bool
    {
        if (! is_array($marker) || ! is_string($marker['id'] ?? null) || $marker['id'] === ''
            || ! in_array($marker['reason'] ?? null, ['post-login', 'post-logout'], true)
            || ! is_int($marker['issued_at'] ?? null)
            || (! $allowUnbound && ! is_string($marker['target'] ?? null))) {
            return false;
        }

        $age = now()->timestamp - $marker['issued_at'];

        return $marker['issued_at'] > 0 && $age >= 0
            && $age < (int) config('ui.auth_splash.pending_ttl_seconds', 30);
    }

    private function eligibleReason(Request $request, mixed $reason, bool $issuing = false): bool
    {
        if ($request->session()->has(TwoFactorService::PENDING_LOGIN_KEY)) {
            return false;
        }

        return match ($reason) {
            'post-login' => $request->user() !== null && ($issuing || ! $request->routeIs('two-factor.challenge', 'two-factor.challenge.store')),
            'post-logout' => $request->user() === null && ($issuing || $this->cleanLogin($request)),
            default => false,
        };
    }

    private function cleanLogin(Request $request): bool
    {
        if (! $request->routeIs('login', 'passport.authorizations.authorize') || $request->user() !== null
            || ($request->routeIs('passport.authorizations.authorize') && ! $request->attributes->get('sso.authorization_valid'))
            || $request->session()->has(TwoFactorService::PENDING_LOGIN_KEY)) {
            return false;
        }

        $errors = $request->session()->get('errors');
        if ($errors instanceof ViewErrorBag) {
            foreach ($errors->getBags() as $bag) {
                if ($bag->any()) {
                    return false;
                }
            }
        }

        foreach (['status', 'warning', 'error'] as $key) {
            if ($request->session()->get($key)) {
                return false;
            }
        }

        return true;
    }

    private function targetPath(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? $request->getScheme());
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            if (! in_array($scheme, ['http', 'https'], true)
                || $scheme !== $request->getScheme()
                || strcasecmp($parts['host'], $request->getHost()) !== 0
                || $port !== $request->getPort()) {
                return null;
            }
        } elseif (isset($parts['scheme']) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function forgetMatching(Request $request, mixed $id): void
    {
        if (is_string($id) && $request->session()->get(self::SESSION_KEY.'.id') === $id) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
