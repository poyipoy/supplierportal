<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class PortalContext
{
    public const SCOPE_IMPORT = 'import';

    public const SCOPE_LOCAL = 'local';

    public const LABEL_IMPORT = 'Material Procurement';

    public const LABEL_LOCAL = 'Local Supplier';

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

        $context = self::resolve($user);

        return match ($context) {
            self::SCOPE_LOCAL => route('local-supplier.dashboard', absolute: false),
            self::SCOPE_IMPORT => route('supplier.dashboard', absolute: false),
            default => route('supplier-context.index', absolute: false),
        };
    }

    public static function isLocal(User $user): bool
    {
        if (! $user->isSupplier()) {
            return $user->isLocalOperator();
        }

        return self::current($user) === self::SCOPE_LOCAL;
    }

    public static function isImport(User $user): bool
    {
        if (! $user->isSupplier()) {
            return false;
        }

        return self::current($user) === self::SCOPE_IMPORT;
    }

    public static function current(?User $user = null): ?string
    {
        $user ??= auth()->user();

        if (! $user instanceof User || ! $user->isSupplier()) {
            return null;
        }

        if (request()->routeIs('local-supplier.*')) {
            return $user->hasSupplierScope(self::SCOPE_LOCAL) ? self::SCOPE_LOCAL : null;
        }

        if (request()->routeIs('supplier.*')) {
            return $user->hasSupplierScope(self::SCOPE_IMPORT) ? self::SCOPE_IMPORT : null;
        }

        return self::resolve($user);
    }

    public static function resolve(User $user): ?string
    {
        if (! $user->isSupplier()) {
            return null;
        }

        $scopes = $user->supplierScopes()->pluck('scope')->all();

        if (count($scopes) === 1) {
            return $scopes[0];
        }

        if (count($scopes) > 1) {
            $context = session('supplier_context');
            if ($context && in_array($context, $scopes, true)) {
                return $context;
            }
        }

        return null;
    }

    public static function isDualScope(?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user instanceof User || ! $user->isSupplier()) {
            return false;
        }

        return $user->hasSupplierScope(self::SCOPE_IMPORT) && $user->hasSupplierScope(self::SCOPE_LOCAL);
    }

    public static function switchTo(Request $request, string $scope): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
            && $user->is_active
            && $user->isSupplier()
            && in_array($scope, [self::SCOPE_IMPORT, self::SCOPE_LOCAL], true)
            && $user->hasSupplierScope($scope),
            403
        );

        $request->session()->put('supplier_context', $scope);
    }

    public static function label(?string $scope): string
    {
        return match ($scope) {
            self::SCOPE_IMPORT => self::LABEL_IMPORT,
            self::SCOPE_LOCAL => self::LABEL_LOCAL,
            default => '',
        };
    }

    public static function description(?string $scope): string
    {
        return match ($scope) {
            self::SCOPE_IMPORT => 'Quotation, PO, Shipment',
            self::SCOPE_LOCAL => 'Invoice, Vendor Profile',
            default => '',
        };
    }
}
