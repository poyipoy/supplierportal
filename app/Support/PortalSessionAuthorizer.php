<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

final class PortalSessionAuthorizer
{
    /**
     * Authorize whether an actor can access a named portal route without leaking unauthorized routes.
     *
     * Passing a non-null $activeSupplierScope (used when building UI lookup maps) restricts supplier
     * routes to that active portal scope for dual-scope suppliers.
     */
    public static function canAccess(?User $user, string $routeName, ?string $activeSupplierScope = null): bool
    {
        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        $route = RouteFacade::getRoutes()->getByName($routeName);
        if (! $route instanceof Route) {
            return false;
        }

        $middlewares = $route->gatherMiddleware();

        if (
            in_array('guest', $middlewares, true)
            || in_array('mfa.pending', $middlewares, true)
            || in_array('password.confirm', $middlewares, true)
        ) {
            return false;
        }

        foreach ($middlewares as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'role:')) {
                $allowedRoles = array_map('trim', explode(',', substr($middleware, 5)));
                if (! in_array($user->role, $allowedRoles, true)) {
                    return false;
                }
            }

            if (str_starts_with($middleware, 'supplier.scope:')) {
                $requiredScope = trim(substr($middleware, 15));
                if (! $user->hasSupplierScope($requiredScope)) {
                    return false;
                }
                if ($activeSupplierScope !== null && $activeSupplierScope !== $requiredScope) {
                    return false;
                }
            }
        }

        return self::passesSupplierDomainRules($user, $routeName, $activeSupplierScope);
    }

    public static function requiredSupplierScope(string $routeName): ?string
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);
        if (! $route instanceof Route) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'supplier.scope:')) {
                $scope = trim(substr($middleware, 15));

                return $scope !== '' ? $scope : null;
            }
        }

        return null;
    }

    private static function passesSupplierDomainRules(User $user, string $routeName, ?string $activeSupplierScope): bool
    {
        if ($user->isLocalOperator() && Str::is(['attachments.*', 'conversations.*', 'shared.pdf.*'], $routeName)) {
            return false;
        }

        if ($user->isSupplier() && ! $user->hasSupplierScope(PortalContext::SCOPE_IMPORT) && Str::is('exports.*', $routeName)) {
            return false;
        }

        if ($user->isSupplier() && Str::is(['supplier.*', 'attachments.*', 'conversations.*', 'shared.pdf.*'], $routeName)) {
            if (! $user->hasSupplierScope(PortalContext::SCOPE_IMPORT)) {
                return false;
            }
            if ($activeSupplierScope !== null && $activeSupplierScope !== PortalContext::SCOPE_IMPORT) {
                return false;
            }
        }

        return true;
    }
}
