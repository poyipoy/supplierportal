<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class LimitOperationalRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $policy = $this->policy($request);

        return $policy === null ? $next($request)
            : app(ThrottleRequests::class)->handle($request, $next, 'resources.'.$policy);
    }

    private function policy(Request $request): ?string
    {
        if ($request->is('broadcasting/auth')) {
            return 'search';
        }
        if (! $request->isMethod('GET') && $request->routeIs(
            'purchasing.export.*', 'supplier.export.*', 'supplier.price-history.export',
            'qc.export.*', 'finance.master-invoices.export', 'finance.drp.export',
            'finance.drp.export-transfer', 'accounting.reports.export'
        )) {
            return 'exports';
        }
        if ($request->isMethod('GET') && $request->routeIs('shared.pdf.*', '*.receipt', '*.print', 'receipts.verify-*')) {
            return 'pdf';
        }
        if ($request->routeIs('*.search', '*.materials', '*.data', '*.data-*', '*.material-calculations.preview', 'exports.definitions.show')) {
            return 'search';
        }
        // Polling and downloads never share the dispatch/upload budgets.
        if (! $request->isMethod('GET') && $request->routeIs(
            'local-supplier.invoices.store', 'local-supplier.invoices.resubmit',
            'ga.claims.store', 'ga.claims.resubmit', 'supplier.register.store', 'supplier.registration.resubmit',
            '*.documents.upload', '*.attachments.store', '*.claims.respond', '*.supplier-audits.result',
            '*.upload-po', '*.import.po.preview', '*.import.gr.preview', '*.quotations.import-preview', '*.requisitions.import-preview', 'finance.overpayments.refund'
        )) {
            return 'uploads';
        }
        if ($request->allFiles() !== [] && $request->routeIs('supplier.quotations.store', 'conversations.messages.store', 'qc.inspections.store')) {
            return 'uploads';
        }

        return null;
    }
}
