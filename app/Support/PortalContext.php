<?php

namespace App\Support;

use App\Models\User;

class PortalContext
{
    public static function dashboard(User $user): string
    {
        if ($user->isFinance()) {
            return route('finance.dashboard', absolute: false);
        }
        if ($user->isGa()) {
            return route('ga.dashboard', absolute: false);
        }
        if ($user->isLocalOperator()) {
            return route('accounting.dashboard', absolute: false);
        }
        if (! $user->isSupplier()) {
            return route($user->role.'.dashboard', absolute: false);
        }

        $scopes = $user->supplierScopes()->pluck('scope')->all();
        $context = session('supplier_context');
        if (count($scopes) > 1 && ! in_array($context, $scopes, true)) {
            return route('supplier-context.index', absolute: false);
        }
        $context = count($scopes) === 1 ? $scopes[0] : $context;

        return match ($context) {
            'local' => route('local-supplier.dashboard', absolute: false),
            'import' => route('supplier.dashboard', absolute: false),
            default => route('supplier-context.index', absolute: false),
        };
    }

    public static function isLocal(User $user): bool
    {
        if (! $user->isSupplier()) {
            return $user->isLocalOperator();
        }
        if (request()->routeIs('local-supplier.*')) {
            return true;
        }
        if (request()->routeIs('supplier.*')) {
            return false;
        }

        return $user->hasSupplierScope('local') && (! $user->hasSupplierScope('import') || session('supplier_context') === 'local');
    }
}
