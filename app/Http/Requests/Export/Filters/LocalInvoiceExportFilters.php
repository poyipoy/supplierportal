<?php

namespace App\Http\Requests\Export\Filters;

use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Models\LocalInvoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocalInvoiceExportFilters
{
    public static function validated(Request $request, bool $accounting = false): array
    {
        $rules = (new InvoiceFilterRequest)->rules();
        if ($accounting) {
            $rules['report'] = ['required', Rule::in(['register', 'payments'])];
        }
        $filters = validator($request->all(), $rules)->validate();
        if (! empty($filters['supplier'])) {
            $supplier = (new User)->resolveRouteBinding($filters['supplier']);
            abort_unless($supplier && ($supplier->hasSupplierScope('local') || LocalInvoice::where('supplier_id', $supplier->id)->exists()), 422);
        }

        return $filters;
    }

    public static function schema(bool $accounting): array
    {
        $fields = [
            ['name' => 'q', 'type' => 'text'], ['name' => 'supplier', 'type' => 'supplier'],
            ['name' => 'status', 'type' => 'select', 'domain' => 'local_invoice', 'options' => LocalInvoice::STATUSES],
            ['name' => 'payment_status', 'type' => 'select', 'labels' => ['ALL' => 'local_invoice.filters.all_invoices', 'UNPAID' => 'finance.drp.unpaid_label', 'PAID' => 'finance.drp.paid_label'], 'options' => ['ALL', 'UNPAID', 'PAID']],
            ['name' => 'overpayment_status', 'type' => 'select', 'labels' => ['all' => 'finance.refund.all_statuses', 'open' => 'local_invoice.filters.refund_open', 'settled' => 'finance.refund.completed_status'], 'options' => ['all', 'open', 'settled']],
            ['name' => 'from', 'type' => 'date'], ['name' => 'to', 'type' => 'date'],
            ['name' => 'due_from', 'type' => 'date'], ['name' => 'due_to', 'type' => 'date'],
            ['name' => 'overdue', 'type' => 'boolean'], ['name' => 'history', 'type' => 'boolean'],
            ['name' => 'period', 'type' => 'text'], ['name' => 'month', 'type' => 'number'], ['name' => 'year', 'type' => 'number'],
        ];
        if ($accounting) {
            $fields[] = ['name' => 'report', 'type' => 'select', 'required' => true, 'default' => 'register', 'labels' => ['register' => 'local_invoice.list.title', 'payments' => 'local_invoice.labels.payment_date'], 'options' => ['register', 'payments']];
        }

        return $fields;
    }
}
