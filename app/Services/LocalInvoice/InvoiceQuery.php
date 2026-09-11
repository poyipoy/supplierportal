<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class InvoiceQuery
{
    public function filtered(array $filters, ?int $owner = null, bool $payments = false): Builder
    {
        $query = LocalInvoice::query()->with(['supplier.supplier', 'receipt']);
        if ($owner !== null) {
            $query->where('supplier_id', $owner);
        }
        if ($payments) {
            $query->whereIn('status', ! empty($filters['history']) ? ['APPROVED', 'PAYMENT_SCHEDULED', 'COMPLETED'] : ['APPROVED', 'PAYMENT_SCHEDULED']);
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($search = $filters['q'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', '%'.$search.'%')->orWhere('submission_number', 'like', '%'.$search.'%')->orWhere('po_number', 'like', '%'.$search.'%')->orWhereHas('receipt', fn ($r) => $r->where('receipt_number', 'like', '%'.$search.'%'));
            });
        }
        if ($supplier = $filters['supplier'] ?? null) {
            $user = (new User)->resolveRouteBinding($supplier);
            abort_unless($user && ($user->hasSupplierScope('local') || LocalInvoice::where('supplier_id', $user->id)->exists()), 422);
            $query->where('supplier_id', $user->id);
        }
        foreach (['from' => ['submitted_at', '>='], 'to' => ['submitted_at', '<='], 'due_from' => ['due_date', '>='], 'due_to' => ['due_date', '<=']] as $key => [$column, $operator]) {
            if (! empty($filters[$key])) {
                $query->whereDate($column, $operator, $filters[$key]);
            }
        }
        if (! empty($filters['overdue'])) {
            $query->whereDate('due_date', '<', today())->whereIn('status', ['APPROVED', 'PAYMENT_SCHEDULED']);
        }

        return $query;
    }

    public function dashboard(?int $owner = null): array
    {
        $query = $this->filtered([], $owner);

        return [
            'counts' => (clone $query)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'overdue' => (clone $query)->whereIn('status', ['APPROVED', 'PAYMENT_SCHEDULED'])->whereDate('due_date', '<', today())->count(),
            'invoices' => $query->latest('id')->limit(5)->get(),
        ];
    }
}
