<?php

namespace App\Http\Middleware;

use App\Support\Security\CspNonce;
use App\Support\ServerTabsResponse;
use App\Support\WorkspaceShell;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class WrapWorkspaceShell
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = WorkspaceShell::enabled();
        $isEmbed = $enabled && WorkspaceShell::isAllowedEmbedRequest($request);

        if (
            $isEmbed
            && $request->isMethod('GET')
            && $request->user()
            && ! $request->ajax()
            && ! $request->expectsJson()
            && $request->input('view') !== 'json'
            && ! ServerTabsResponse::wants($request)
        ) {
            $prerenderKey = WorkspaceShell::prerenderCacheKey($request);
            $cached = $prerenderKey !== null ? Cache::pull($prerenderKey) : null;

            if (is_array($cached) && is_string($cached['content'] ?? null)) {
                $oldNonce = (string) ($cached['nonce'] ?? '');
                $newNonce = app(CspNonce::class)->get();
                $html = ($oldNonce !== '' && $oldNonce !== $newNonce)
                    ? str_replace('nonce="'.$oldNonce.'"', 'nonce="'.$newNonce.'"', $cached['content'])
                    : $cached['content'];

                $headers = [
                    'Content-Type' => (string) ($cached['content_type'] ?? 'text/html; charset=UTF-8'),
                ];
                if (! empty($cached['cache_control'])) {
                    $headers['Cache-Control'] = (string) $cached['cache_control'];
                }

                return response($html, 200, $headers);
            }
        }

        $isWrapCandidate = $enabled
            && ! $isEmbed
            && $request->isMethod('GET')
            && $request->user() !== null
            && ! $request->ajax()
            && ! $request->expectsJson()
            && $request->input('view') !== 'json'
            && ! ServerTabsResponse::wants($request)
            && WorkspaceShell::isShellEligibleRoute($request->route());

        View::share('isWorkspaceEmbed', $isWrapCandidate ? true : $isEmbed);
        View::share('workspaceShellEnabled', $enabled);
        if ($isWrapCandidate) {
            $request->attributes->set('workspace.prerender_embed', true);
        }

        try {
            $response = $next($request);
        } finally {
            if ($isWrapCandidate) {
                View::share('isWorkspaceEmbed', $isEmbed);
                $request->attributes->remove('workspace.prerender_embed');
            }
        }

        if (! $enabled) {
            return $response;
        }

        if ($response instanceof RedirectResponse && WorkspaceShell::originatedFromEmbed($request)) {
            $targetUrl = $response->getTargetUrl();
            if (WorkspaceShell::isEligibleRedirectTarget($request, $targetUrl)) {
                $response->setTargetUrl(WorkspaceShell::appendEmbedParam($targetUrl));
            }

            return $response;
        }

        if (
            ! $request->isMethod('GET')
            || $isEmbed
            || ! $request->user()
            || $request->ajax()
            || $request->expectsJson()
            || $request->input('view') === 'json'
            || ServerTabsResponse::wants($request)
        ) {
            return $response;
        }

        if (
            ! ($response instanceof LaravelResponse)
            || $response->getStatusCode() !== 200
            || $response->isRedirection()
        ) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $route = $request->route();
        if (! WorkspaceShell::isShellEligibleRoute($route)) {
            return $response;
        }

        $content = (string) $response->getContent();
        if (! str_contains($content, '<meta name="portal-scope"')) {
            return $response;
        }

        if ($isWrapCandidate) {
            $prerenderKey = WorkspaceShell::prerenderCacheKey($request);
            if ($prerenderKey !== null) {
                Cache::put($prerenderKey, [
                    'content' => $content,
                    'nonce' => app(CspNonce::class)->get(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                    'cache_control' => (string) ($response->headers->get('Cache-Control') ?? ''),
                ], now()->addSeconds(15));
            }
        }

        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        $shellHtml = view('layouts.workspace-shell', WorkspaceShell::shellViewData($request, $content, $route))->render();

        return $response->setContent($shellHtml);
    }
}
