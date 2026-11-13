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
use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\Pricing\MaterialReferencePriceService;
use App\Services\Pricing\ReferencePriceRuleImportService;
use App\Support\ServerTabsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaterialReferencePriceController extends Controller
{
    public function __construct(
        private readonly MaterialReferencePriceService $prices,
        private readonly ReferencePriceRuleImportService $ruleImportService
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        Gate::authorize('viewAny', MaterialReferencePrice::class);

        $activeTab = $request->query('tab', 'rules');
        if (! in_array($activeTab, ['rules', 'benchmark', 'legacy'], true)) {
            $activeTab = ($activeTab === 'historical') ? 'benchmark' : 'rules';
        }

        $rules = ReferencePriceRule::query()
            ->orderBy('sort_order')
            ->orderBy('dimension_min')
            ->get();

        // Legacy Archive data
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'shape' => ['nullable', Rule::in([PrItem::SHAPE_FLAT, PrItem::SHAPE_ROUND, PrItem::SHAPE_HOLLOW])],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
            'status' => ['nullable', Rule::in(['active', 'void', 'all'])],
        ]);
        $status = $filters['status'] ?? MaterialReferencePrice::STATUS_ACTIVE;

        $legacyPrices = MaterialReferencePrice::query()
            ->with(['materialMaster:id,material_code', 'creator:id,name', 'voider:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filters['shape'] ?? null, fn ($query, $shape) => $query->where('shape', $shape))
            ->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where('currency', $currency))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('materialMaster',
                fn ($material) => $material->where('material_code', 'like', '%'.$search.'%')))
            ->orderBy('material_master_id')->orderBy('shape')->orderBy('currency')->orderBy('dimension_min')->orderByDesc('valid_from')
            ->paginate(25)
            ->withQueryString();

        $materialOptions = MaterialMaster::query()
            ->where('is_active', true)
            ->orderBy('material_code')
            ->get(['normalized_code', 'material_code', 'raw_category'])
            ->map(fn (MaterialMaster $material) => [
                'value' => $material->normalized_code,
                'label' => $material->material_code,
                'sublabel' => $material->raw_category,
            ])
            ->all();

        $prefix = $this->resolveIndexRoutePrefix($request);

        $viewData = [
            'activeTab' => $activeTab,
            'rules' => $rules,
            'prices' => $legacyPrices,
            'legacyPrices' => $legacyPrices,
            'filters' => [...$filters, 'status' => $status],
            'shapes' => [PrItem::SHAPE_FLAT, PrItem::SHAPE_ROUND, PrItem::SHAPE_HOLLOW],
            'currencies' => ExchangeRate::CURRENCIES,
            'materialOptions' => $materialOptions,
            'prefix' => $prefix,
            'rp' => 'materials.reference_prices',
            'preview' => session('import_preview'),
            'importId' => session('import_id'),
            'importFilename' => session('import_filename'),
        ];

        if (ServerTabsResponse::wants($request)) {
            return ServerTabsResponse::make(
                view('purchasing.reference-prices._content', $viewData)->render(),
                $activeTab,
                $request,
                ['nav' => view('purchasing.reference-prices._tabs', $viewData)->render()]
            )->header('Cache-Control', 'private, no-store');
        }

        return view('purchasing.reference-prices.index', $viewData);
    }

    public function importPreview(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', ReferencePriceRule::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,bin', 'max:10240'],
            'import_mode' => ['required', Rule::in([ReferencePriceImport::MODE_REPLACE, ReferencePriceImport::MODE_MERGE])],
            'sheet_name' => ['nullable', 'string', 'max:50'],
        ]);

        $file = $request->file('file');
        $sheetName = $validated['sheet_name'] ?? 'Kategori';
        $mode = $validated['import_mode'];

        app(FileInspectionService::class)->inspectUpload($file, 'spreadsheet_import', 'file');

        $sourcePath = $file->getPathname();
        if ($sourcePath === '' || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            $sourcePath = $file->getRealPath() ?: '';
        }

        if ($sourcePath === '' || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            $msg = 'Uploaded file could not be read from temporary directory.';

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }

        $relativeDir = 'private/reference_price_imports';
        Storage::makeDirectory($relativeDir);
        $fileName = $file->hashName();
        $path = $relativeDir.'/'.$fileName;
        $fullPath = Storage::path($path);

        if (! copy($sourcePath, $fullPath)) {
            $msg = 'Failed to store uploaded file.';

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $msg], 500)
                : back()->with('error', $msg);
        }

        $fileHash = hash_file('sha256', $fullPath);

        try {
            $parsed = $this->ruleImportService->parseWorkbook($fullPath, $sheetName);
        } catch (\Throwable $e) {
            Storage::delete($path);

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        $preview = $this->ruleImportService->buildPreview($parsed, $mode);

        $import = ReferencePriceImport::create([
            'uploaded_by' => $request->user()->id,
            'source_file_name' => $file->getClientOriginalName(),
            'private_storage_path' => $path,
            'source_file_hash' => $fileHash,
            'source_sheet' => $sheetName,
            'import_mode' => $mode,
            'status' => ReferencePriceImport::STATUS_PREVIEWED,
            'parsed_count' => count($parsed),
            'preview_diff' => $preview,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'import_id' => $import->id,
                'preview' => $preview,
            ]);
        }

        return back()->with([
            'import_preview' => $preview,
            'import_id' => $import->id,
            'import_filename' => $file->getClientOriginalName(),
        ]);
    }

    public function importConfirm(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', ReferencePriceRule::class);

        $validated = $request->validate([
            'import_id' => ['required', 'exists:reference_price_imports,id'],
        ]);

        $import = ReferencePriceImport::findOrFail($validated['import_id']);

        if ($import->status !== ReferencePriceImport::STATUS_PREVIEWED) {
            $msg = 'Import session has expired or was already confirmed.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $previewDiff = $import->preview_diff;
        if (empty($previewDiff['valid']) || empty($previewDiff['candidate_rules'])) {
            $msg = 'Cannot confirm an import with validation conflicts.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }

        $this->ruleImportService->commit($import, $previewDiff['candidate_rules'], $request->user());

        $msg = __('materials.reference_prices.rules.import_success');
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        }

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
            ->with('success', $msg);
    }

    public function saveCategory(Request $request): RedirectResponse
    {
        Gate::authorize('create', ReferencePriceRule::class);

        $validated = $request->validate([
            'category_key' => ['required', 'string', 'max:50'],
            'category_name' => ['required', 'string', 'max:100'],
            'dimension_min' => ['required', 'integer', 'min:1'],
            'dimension_max' => ['required', 'integer', 'min:1', 'gte:dimension_min'],
        ]);

        $existing = ReferencePriceRule::where('category_key', $validated['category_key'])->first();
        if ($existing) {
            Gate::authorize('update', $existing);
        }

        $allRules = ReferencePriceRule::query()
            ->orderBy('dimension_min')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'category_key' => $r->category_key,
                'category_name' => $r->category_name,
                'dimension_min' => $r->dimension_min,
                'dimension_max' => $r->dimension_max,
                'sort_order' => $r->sort_order,
                'status' => $r->status,
            ])->all();

        $found = false;
        foreach ($allRules as &$r) {
            if ($r['category_key'] === $validated['category_key']) {
                $r['category_name'] = $validated['category_name'];
                $r['dimension_min'] = (int) $validated['dimension_min'];
                $r['dimension_max'] = (int) $validated['dimension_max'];
                $found = true;
                break;
            }
        }
        unset($r);

        if (! $found) {
            $allRules[] = [
                'category_key' => $validated['category_key'],
                'category_name' => $validated['category_name'],
                'dimension_min' => (int) $validated['dimension_min'],
                'dimension_max' => (int) $validated['dimension_max'],
                'sort_order' => count($allRules) + 1,
                'status' => ReferencePriceRule::STATUS_ACTIVE,
            ];
        }

        $errors = $this->ruleImportService->validateRuleIntervals($allRules);
        if (! empty($errors)) {
            return back()->with('error', implode('; ', $errors));
        }

        usort($allRules, fn ($a, $b) => $a['dimension_min'] <=> $b['dimension_min']);

        DB::transaction(function () use ($allRules, $request) {
            foreach ($allRules as $idx => $r) {
                ReferencePriceRule::updateOrCreate(
                    ['category_key' => $r['category_key']],
                    [
                        'category_name' => $r['category_name'],
                        'dimension_min' => $r['dimension_min'],
                        'dimension_max' => $r['dimension_max'],
                        'sort_order' => $idx + 1,
                        'status' => $r['status'] ?? ReferencePriceRule::STATUS_ACTIVE,
                        'updated_by' => $request->user()->id,
                        'created_by' => DB::raw('COALESCE(created_by, '.(int) $request->user()->id.')'),
                    ]
                );
            }
        });

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
            ->with('success', __('materials.reference_prices.rules.saved_success'));
    }

    public function toggleCategoryStatus(Request $request, ReferencePriceRule $rule): RedirectResponse
    {
        Gate::authorize('update', $rule);

        $targetStatus = $rule->status === ReferencePriceRule::STATUS_ACTIVE
            ? ReferencePriceRule::STATUS_INACTIVE
            : ReferencePriceRule::STATUS_ACTIVE;

        if ($targetStatus === ReferencePriceRule::STATUS_ACTIVE) {
            $activeRules = ReferencePriceRule::where('id', '!=', $rule->id)
                ->where('status', ReferencePriceRule::STATUS_ACTIVE)
                ->get()
                ->map(fn ($r) => [
                    'category_key' => $r->category_key,
                    'dimension_min' => $r->dimension_min,
                    'dimension_max' => $r->dimension_max,
                    'status' => $r->status,
                ])
                ->all();

            $activeRules[] = [
                'category_key' => $rule->category_key,
                'dimension_min' => $rule->dimension_min,
                'dimension_max' => $rule->dimension_max,
                'status' => ReferencePriceRule::STATUS_ACTIVE,
            ];

            $errors = $this->ruleImportService->validateRuleIntervals($activeRules);
            if (! empty($errors)) {
                return back()->with('error', implode('; ', $errors));
            }
        }

        $rule->update([
            'status' => $targetStatus,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
            ->with('success', __('materials.reference_prices.rules.toggle_success'));
    }

    public function store(SaveMaterialReferencePriceRequest $request): RedirectResponse
    {
        $this->prices->create($request->payload(), $request->user());

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'legacy'])
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

    public function resolveIndexRoutePrefix(Request $request): string
    {
        return $request->user()?->role === 'admin'
            ? 'admin.reference-prices'
            : 'purchasing.master-data.reference-prices';
    }

    public function resolveIndexRouteName(Request $request): string
    {
        return $this->resolveIndexRoutePrefix($request).'.index';
    }
}
