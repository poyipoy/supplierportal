<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $contentType = (string) $response->headers->get('Content-Type');

        if (! str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy-Report-Only', $this->contentSecurityPolicy());

        if (config('app.env') === 'production' && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net https://cdn.datatables.net",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net https://challenges.cloudflare.com",
            "connect-src 'self' ws: wss: https://challenges.cloudflare.com",
            'frame-src https://challenges.cloudflare.com',
        ];

        $reportUri = (string) config('auth_security.headers.csp_report_uri');

        if (filter_var($reportUri, FILTER_VALIDATE_URL) !== false && ! preg_match('/[\r\n;]/', $reportUri)) {
            $directives[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $directives);
    }
}
