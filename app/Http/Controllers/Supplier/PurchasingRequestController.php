<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Read-only Purchasing Request Overview (Gate 2 SUP-01). Visibility comes from
 * PurchaseRequisition::accessibleBySupplier(); actions come from
 * supplierQuotationAction(), so the overview never offers more than create()/show().
 */
class PurchasingRequestController extends Controller
{
    private const STATUS_FILTERS = ['unresponded', 'draft', 'revision_requested', 'submitted', 'accepted', 'rejected', 'all_unavailable'];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Quotation::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'period_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
        ]);
        $supplierId = (int) auth()->id();

        $query = $this->accessibleQuery($supplierId)
            ->with('period')
            ->withCount('items');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where('pr_number', 'like', '%'.$search.'%');
        }
        if (! empty($filters['period_id'])) {
            $query->where('period_id', $filters['period_id']);
        }
        if (($filters['status'] ?? null) === 'unresponded') {
            $query->whereIn('status', ['submitted', 'bidding'])
                ->whereDoesntHave('quotations', fn ($q) => $q->where('supplier_id', $supplierId));
        } elseif (! empty($filters['status'])) {
            $query->whereHas('quotations', fn ($q) => $q->where('supplier_id', $supplierId)->where('status', $filters['status']));
        }

        $requisitions = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $periods = Period::query()
            ->whereHas('purchaseRequisitions', fn ($q) => $q->accessibleBySupplier($supplierId))
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')
            ->get();

        return view('supplier.requests.index', compact('requisitions', 'periods', 'filters'));
    }

    public function show($pr_id)
    {
        Gate::authorize('viewAny', Quotation::class);
        $supplierId = (int) auth()->id();

        $pr = $this->accessibleQuery($supplierId)
            ->with(['period', 'items' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($pr_id);
        $action = $pr->supplierQuotationAction(auth()->user());

        return view('supplier.requests.show', compact('pr', 'action'));
    }

    /** Only the supplier's own quotation and invitation flags; never the invitation list. */
    private function accessibleQuery(int $supplierId): Builder
    {
        return PurchaseRequisition::query()
            ->accessibleBySupplier($supplierId)
            ->with(['quotations' => fn ($q) => $q->where('supplier_id', $supplierId)])
            ->withExists([
                'invitedSuppliers as has_invitations',
                'invitedSuppliers as is_invited' => fn ($q) => $q->where('users.id', $supplierId),
            ]);
    }
}
