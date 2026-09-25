<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PrItemAward;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AwardConsolidationController extends Controller
{
    public function create(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'supplier_id' => 'nullable|string',
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
        ]);
        $supplier = null;
        if ($request->filled('supplier_id')) {
            $supplier = (new User)->resolveRouteBinding($filters['supplier_id']);
            abort_unless($supplier && $supplier->role === 'supplier', 404);
        }

        $awards = PrItemAward::unassignedToPo()
            ->with(['purchaseRequisition', 'prItem', 'supplier', 'quotation.exchangeRate', 'quotationItem.prItem'])
            ->whereHas('quotation', fn ($query) => $query
                ->whereIn('status', Quotation::AWARD_ELIGIBLE_STATUSES)
                ->whereDoesntHave('purchaseOrders', fn ($po) => $po->withTrashed()))
            ->whereHas('quotationItem', fn ($query) => $query->where('is_available', true))
            ->when($supplier, fn ($query) => $query->where('supplier_id', $supplier->id))
            ->when($request->filled('currency'), fn ($query) => $query->whereHas('quotation',
                fn ($quotation) => $quotation->where('currency', $filters['currency'])))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($search) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $search->whereHas('purchaseRequisition', fn ($pr) => $pr->where('pr_number', 'like', $term))
                    ->orWhereHas('prItem', fn ($item) => $item->where('material_name', 'like', $term));
            }))
            ->orderBy('supplier_id')->orderBy('pr_id')->orderBy('pr_item_id')
            ->paginate(50)->withQueryString();
        $suppliers = User::importEligible()
            ->orWhereIn('id', DB::table('pr_item_awards')->distinct()->pluck('supplier_id'))
            ->orderBy('name')->get(['id', 'name']);

        return view('purchasing.po.consolidate-awards', compact('awards', 'suppliers'));
    }

    public function store(Request $request, PurchaseOrderGenerationService $generator)
    {
        $validated = $request->validate([
            'award_ids' => 'required|array|min:1|max:1000',
            'award_ids.*' => 'required|integer|distinct|exists:pr_item_awards,id',
            'estimated_arrival' => 'required|date',
            'notes' => 'nullable|string|max:5000',
        ]);

        try {
            // The service reloads awards and quotation eligibility under the
            // shared PR -> PR item -> quotation -> item -> award lock order.
            $pos = $generator->generateFromAwards($validated['award_ids'], $request->user(), [
                'require_single_supplier' => true,
                'estimated_arrival' => $validated['estimated_arrival'],
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['award_ids' => $exception->getMessage()]);
        }

        return redirect()->route('purchasing.purchase-orders.show', $pos->first())
            ->with('success', 'Selected item awards consolidated into one Purchase Order.');
    }
}
