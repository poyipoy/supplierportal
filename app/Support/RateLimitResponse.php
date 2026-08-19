<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitResponse
{
    /**
     * Return the appropriate response for a named rate limiter.
     *
     * @param  array<string, int|string>  $headers
     */
    public static function forNamedLimiter(Request $request, array $headers): Response
    {
        if ($request->expectsJson()) {
            return self::json($headers);
        }

        $context = self::fullPageContext($request->route()?->getName());

        return $context === null
            ? self::warningRedirect($request, $headers)
            : self::fullPage($headers, $context);
    }

    /**
     * Return the dedicated response for the primary sign-in limiter.
     *
     * @param  array<string, int|string>  $headers
     */
    public static function forLogin(Request $request, array $headers): Response
    {
        if ($request->expectsJson()) {
            return self::json($headers);
        }

        return self::fullPage($headers, self::fullPageContext('login.store'));
    }

    /**
     * Return a safe redirect and the existing warning toast for regular HTML throttles.
     *
     * @param  array<string, int|string>  $headers
     */
    public static function warningRedirect(Request $request, array $headers): RedirectResponse
    {
        $response = redirect()
            ->to(self::safeReturnUrl($request))
            ->with('warning', self::warningMessage(self::retryAfter($headers)));

        return self::applyHeaders($response, $headers);
    }

    /**
     * @param  array<string, int|string>  $headers
     * @param  array{returnUrl: string, returnLabel: string}  $context
     */
    private static function fullPage(array $headers, array $context): Response
    {
        $response = response()->view('auth.rate-limited', [
            'retryAfter' => self::retryAfter($headers),
            'returnUrl' => $context['returnUrl'],
            'returnLabel' => $context['returnLabel'],
        ], 429);

        return self::applyHeaders($response, $headers);
    }

    /**
     * @param  array<string, int|string>  $headers
     */
    private static function json(array $headers): Response
    {
        return self::applyHeaders(
            response()->json(['message' => 'Too Many Attempts.'], 429),
            $headers,
        );
    }

    /**
     * @return array{returnUrl: string, returnLabel: string}|null
     */
    private static function fullPageContext(?string $routeName): ?array
    {
        return match ($routeName) {
            'login.store' => [
                'returnUrl' => route('login'),
                'returnLabel' => 'Back to Sign In',
            ],
            'password.email' => [
                'returnUrl' => route('password.request'),
                'returnLabel' => 'Back to Forgot Password',
            ],
            'two-factor.challenge.store' => [
                'returnUrl' => route('two-factor.challenge'),
                'returnLabel' => 'Back to Verification',
            ],
            'profile.two-factor.start' => [
                'returnUrl' => route('profile.edit'),
                'returnLabel' => 'Back to Profile',
            ],
            'profile.two-factor.confirm' => [
                'returnUrl' => route('profile.two-factor.setup'),
                'returnLabel' => 'Back to MFA Setup',
            ],
            default => null,
        };
    }

    private static function safeReturnUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');

        if (is_string($referer) && self::isSameOrigin($request, $referer)) {
            return $referer;
        }

        if ($request->route()?->getName() === 'password.store') {
            return route('password.request');
        }

        return $request->user() === null ? route('login') : route('profile.edit');
    }

    private static function isSameOrigin(Request $request, string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || $scheme !== $request->getScheme()) {
            return false;
        }

        if (strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
            return false;
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        return $port === $request->getPort();
    }

    /**
     * @param  array<string, int|string>  $headers
     */
    private static function retryAfter(array $headers): int
    {
        $retryAfter = $headers['Retry-After'] ?? $headers['retry-after'] ?? 0;

        return is_numeric($retryAfter) ? max(0, (int) $retryAfter) : 0;
    }

    private static function warningMessage(int $retryAfter): string
    {
        if ($retryAfter <= 0) {
            return 'Too many requests. Please wait a moment before trying again.';
        }

        if ($retryAfter > 60) {
            $minutes = (int) ceil($retryAfter / 60);

            return 'Too many requests. Please wait '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').' before trying again.';
        }

        return 'Too many requests. Please wait '.$retryAfter.' '.($retryAfter === 1 ? 'second' : 'seconds').' before trying again.';
    }

    /**
     * @param  array<string, int|string>  $headers
     * @template TResponse of Response
     * @param  TResponse  $response
     * @return TResponse
     */
    private static function applyHeaders(Response $response, array $headers): Response
    {
        $response->headers->add($headers);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
