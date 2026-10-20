<?php

namespace App\Http\Controllers;

use App\Support\PortalPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PortalLegacyPathController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->method(), ['GET', 'HEAD'], true), 404);
        $path = $request->getPathInfo();
        $stripped = PortalPath::strip($path);
        $candidates = PortalPath::enabled() ? [PortalPath::path($path), PortalPath::path($stripped)] : [$stripped];
        foreach (array_unique($candidates) as $target) {
            if ($target === $path || ! str_starts_with($target, '/') || str_starts_with($target, '//')
                || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($target))) {
                continue;
            }
            try {
                $matched = Route::getRoutes()->match(Request::create($target, $request->method()));
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                continue;
            }
            if ($matched->isFallback) {
                continue;
            }
            $query = $request->server->get('QUERY_STRING', '');

            return new RedirectResponse($target.($query !== '' ? '?'.$query : ''), 301);
        }

        abort(404);
    }
}
