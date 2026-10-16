<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferencePrice\SaveMaterialReferencePriceRequest;
use App\Http\Requests\ReferencePrice\SupersedeMaterialReferencePriceRequest;
use App\Http\Requests\ReferencePrice\VoidMaterialReferencePriceRequest;
use App\Models\ExchangeRate;
use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use App\Services\Pricing\MaterialReferencePriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mission 06B: Master Reference Price for Purchasing. Create, supersede,
 * void and notes only; no price edit and no delete (plan section 12.2).
 */
class MaterialReferencePriceController extends Controller
{
    public function __construct(private readonly MaterialReferencePriceService $prices) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MaterialReferencePrice::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'shape' => ['nullable', Rule::in([PrItem::SHAPE_FLAT, PrItem::SHAPE_ROUND, PrItem::SHAPE_HOLLOW])],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
            'status' => ['nullable', Rule::in(['active', 'void', 'all'])],
        ]);
        $status = $filters['status'] ?? MaterialReferencePrice::STATUS_ACTIVE;

        $prices = MaterialReferencePrice::query()
            ->with(['materialMaster:id,material_code', 'creator:id,name', 'voider:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filters['shape'] ?? null, fn ($query, $shape) => $query->where('shape', $shape))
            ->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('currency', $currency))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('materialMaster',
                fn ($material) => $material->where('material_code', 'like', '%'.$search.'%')))
            ->orderBy('material_master_id')->orderBy('shape')->orderBy('currency')->orderBy('dimension_min')->orderByDesc('valid_from')
            ->paginate(25)
            ->withQueryString();

        return view('purchasing.reference-prices.index', [
            'prices' => $prices,
            'filters' => [...$filters, 'status' => $status],
            'shapes' => [PrItem::SHAPE_FLAT, PrItem::SHAPE_ROUND, PrItem::SHAPE_HOLLOW],
            'currencies' => ExchangeRate::CURRENCIES,
            // Searchable Material Code: the value is the unique normalized code
            // the request already resolves; the label is the master's code.
            'materialOptions' => MaterialMaster::query()
                ->where('is_active', true)
                ->orderBy('material_code')
                ->get(['normalized_code', 'material_code', 'raw_category'])
                ->map(fn (MaterialMaster $material) => [
                    'value' => $material->normalized_code,
                    'label' => $material->material_code,
                    'sublabel' => $material->raw_category,
                ])
                ->all(),
        ]);
    }

    public function store(SaveMaterialReferencePriceRequest $request): RedirectResponse
    {
        $this->prices->create($request->payload(), $request->user());

        return redirect()->route('purchasing.master-data.reference-prices.index')
            ->with('success', __('materials.reference_prices.created'));
    }

    public function supersede(SupersedeMaterialReferencePriceRequest $request, MaterialReferencePrice $referencePrice): RedirectResponse
    {
        $this->prices->supersede($referencePrice, $request->validated(), $request->user());

        return back()->with('success', __('materials.reference_prices.superseded'));
    }

    public function void(VoidMaterialReferencePriceRequest $request, MaterialReferencePrice $referencePrice): RedirectResponse
    {
        $this->prices->void($referencePrice, $request->validated('void_reason'), $request->user());

        return back()->with('success', __('materials.reference_prices.voided'));
    }

    public function notes(Request $request, MaterialReferencePrice $referencePrice): RedirectResponse
    {
        Gate::authorize('updateNotes', $referencePrice);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $this->prices->updateNotes($referencePrice, $validated['notes'] ?? null, $request->user());

        return back()->with('success', __('materials.reference_prices.notes_saved'));
    }
}
