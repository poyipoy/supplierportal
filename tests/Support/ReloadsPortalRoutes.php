<?php

namespace Tests\Support;

use Illuminate\Routing\RouteCollection;

trait ReloadsPortalRoutes
{
    private function reloadPortalRoutes(array $config = []): void
    {
        config($config);
        $router = app('router');
        $external = array_filter($router->getRoutes()->getRoutes(),
            fn ($route): bool => in_array($route->uri(), ['up', 'oauth/token', 'broadcasting/auth'], true));
        $router->setRoutes(new RouteCollection);
        $router->middleware('web')->group(base_path('routes/web.php'));
        foreach ($external as $route) {
            $router->getRoutes()->add($route);
        }
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
