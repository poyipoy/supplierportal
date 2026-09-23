<?php

namespace App\Http\Middleware;

use App\Models\Attachment;
use App\Models\SupplierOverpaymentRefund;
use Closure;
use Illuminate\Http\Request;

class EnforceSupplierDomain
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('attachments.show')) {
            $attachment = Attachment::find($request->route('id'));
            if ($attachment && $attachment->attachable_type === SupplierOverpaymentRefund::class) {
                return $next($request);
            }
        }

        if ($request->user()?->isLocalOperator() && $request->routeIs('attachments.*', 'conversations.*', 'shared.pdf.*')) {
            abort(403);
        }
        if ($request->user()?->isSupplier() && ! $request->user()->hasSupplierScope('import') && $request->routeIs('exports.*')) {
            abort(403);
        }
        // Shared Import endpoints are outside the supplier route group.
        if ($request->user()?->isSupplier() && $request->routeIs('supplier.*', 'attachments.*', 'conversations.*', 'shared.pdf.*')) {
            abort_unless($request->user()->hasSupplierScope('import'), 403);
        }

        return $next($request);
    }
}
