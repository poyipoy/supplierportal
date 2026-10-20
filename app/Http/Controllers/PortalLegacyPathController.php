<?php

namespace App\Http\Controllers;

use App\Support\PortalPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
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

        $allowed = self::allowedMethods($request);
        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed);
        }
        abort(404);
    }

    public function options(Request $request): Response
    {
        $allowed = self::allowedMethods($request);
        abort_if($allowed === [], 404);

        return response('', 200, ['Allow' => implode(',', $allowed)]);
    }

    /** Match only real endpoints, so compatibility fallbacks cannot invent supported verbs. */
    public static function allowedMethods(Request $request): array
    {
        $allowed = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $route->isFallback && $route->matches($request, false)) {
                $allowed = array_merge($allowed, $route->methods());
            }
        }

        return array_values(array_intersect(Router::$verbs, $allowed));
    }
}
