<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Vinkla\Hashids\Facades\Hashids;

class DecodeHashids
{
    /**
     * Route parameter keys for models that use HasHashids.
     * Only these keys will be decoded from hash to integer.
     *
     * TWO naming conventions exist in this project:
     *  1. Manual routes with explicit {id}: `/purchase-orders/{id}`, `/inspections/{id}`, etc.
     *  2. Route::resource() which auto-names the parameter as the SINGULAR of the resource:
     *       Route::resource('requisitions')  → {requisition}
     *       Route::resource('quotations')    → {quotation}
     *       Route::resource('claims')        → {claim}
     *       Route::resource('users')         → {user}
     *
     * Models WITHOUT HasHashids (Attachment, Period, Notification, Announcement, ExchangeRate)
     * are intentionally excluded — their URLs stay as plain integers.
     */
    protected const HASHED_PARAM_KEYS = [
        // ── Manual {id} routes ─────────────────────────────────────────────────
        'id',            // PurchaseOrder, Claim (supplier), QcInspection, Conversation show routes
        'pr_id',         // Supplier: quotations/create, quotations/store
        'po_id',         // QC: inspections/create, inspections/store; Purchasing: conversations/start-po
        'quotation_id',  // Purchasing: purchase-orders/create
        'inspection_id', // Purchasing: claims/create
        'supplier_id',   // Purchasing: conversations/start-pr

        // ── Route::resource() auto-named singular parameters ───────────────────
        'requisition',   // Route::resource('requisitions') → PurchaseRequisition
        'quotation',     // Route::resource('quotations')   → Quotation (purchasing + supplier)
        'claim',         // Route::resource('claims')       → MaterialClaim (purchasing resource)
        'user',          // Route::resource('users')        → User (admin)
    ];

    /**
     * Route names whose {id} parameter should NOT be decoded (plain-integer models).
     * These use Attachment, Period, Notification, or Announcement — no HasHashids.
     */
    protected const PLAIN_ROUTE_PREFIXES = [
        'attachments.',
        'notifications.',
        'admin.announcements.',
        'supplier.announcements.',
        'admin.exchange-rates.',
        'purchasing.periods.',
        'purchasing.po-documents.',
        'purchasing.pr-items.',
        'purchasing.pdf.',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($route = $request->route()) {
            $routeName = $route->getName() ?? '';

            // Skip decoding entirely for routes that use plain-integer models
            foreach (self::PLAIN_ROUTE_PREFIXES as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    return $next($request);
                }
            }

            $parameters = $route->parameters();

            foreach ($parameters as $key => $value) {
                // Only decode parameters for known hashed models
                if (!in_array($key, self::HASHED_PARAM_KEYS, true)) {
                    continue;
                }

                // Skip if it is already a plain integer — never try to decode a raw numeric string
                // (this prevents Hashids from accidentally mapping '5' → wrong ID)
                if (!is_string($value) || ctype_digit($value)) {
                    continue;
                }

                $decoded = Hashids::decode($value);
                if (!empty($decoded)) {
                    $route->setParameter($key, $decoded[0]);
                }
            }
        }

        return $next($request);
    }
}

