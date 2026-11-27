<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferencePrice\SaveMaterialReferencePriceRequest;
use App\Http\Requests\ReferencePrice\SupersedeMaterialReferencePriceRequest;
use App\Http\Requests\ReferencePrice\VoidMaterialReferencePriceRequest;
use App\Models\ExchangeRate;
use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\Pricing\HistoricalReferencePriceService;
use App\Services\Pricing\MaterialReferencePriceService;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Services\Pricing\ReferencePriceRuleImportService;
use App\Services\Pricing\ReferencePriceRuleResolver;
use App\Support\Pricing\EvaluationContext;
use App\Support\ServerTabsResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

        // Legacy Archive & Historical Benchmark filters
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'shape' => ['nullable', Rule::in([PrItem::SHAPE_FLAT, PrItem::SHAPE_ROUND, PrItem::SHAPE_HOLLOW])],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
            'status' => ['nullable', Rule::in(['active', 'void', 'all'])],
            'po_status' => ['nullable', Rule::in(['completed', 'active', 'waiting_qc', 'overdue', 'all'])],
            'history_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? MaterialReferencePrice::STATUS_ACTIVE;
        $poStatusFilter = $filters['po_status'] ?? 'completed';
        $targetCurrency = $filters['currency'] ?? ExchangeRate::CURRENCY_IDR;
        $historyPage = max(1, (int) ($filters['history_page'] ?? 1));
        $historyPerPage = 25;

        $historyService = app(PurchaseHistoryPriceService::class);
        $historyResult = $historyService->lines([
            'search' => $filters['search'] ?? null,
            'shape' => $filters['shape'] ?? null,
            'po_status' => $poStatusFilter,
        ], $targetCurrency, EvaluationContext::current(), $historyPage, $historyPerPage);

        $historicalLines = new LengthAwarePaginator(
            $historyResult['rows'],
            $historyResult['total'],
            $historyPerPage,
            $historyPage,
            [
                'path' => $request->url(),
                'pageName' => 'history_page',
                'query' => array_merge($request->query(), ['tab' => 'benchmark']),
            ]
        );

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
            'historicalLines' => $historicalLines,
            'poStatusFilter' => $poStatusFilter,
            'targetCurrency' => $targetCurrency,
            'filters' => [...$filters, 'status' => $status, 'po_status' => $poStatusFilter],
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
        } catch (\InvalidArgumentException $e) {
            Storage::delete($path);

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Storage::delete($path);
            report($e);
            $msg = __('purchasing.copy.the_spreadsheet_could_not_be_read_verify_the_file_and_try_again');

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
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
            'form_mode' => ['nullable', Rule::in(['create', 'edit'])],
            'rule_id' => ['nullable', 'integer', 'exists:reference_price_rules,id'],
        ]);

        $formMode = $validated['form_mode'] ?? null;
        $ruleId = isset($validated['rule_id']) ? (int) $validated['rule_id'] : null;
        $categoryKey = trim((string) $validated['category_key']);
        $categoryName = trim((string) $validated['category_name']);
        $dimMin = (int) $validated['dimension_min'];
        $dimMax = (int) $validated['dimension_max'];

        $errorMessage = DB::transaction(function () use ($request, $formMode, $ruleId, $categoryKey, $categoryName, $dimMin, $dimMax): ?string {
            $lockedRules = ReferencePriceRule::query()
                ->orderBy('dimension_min')
                ->lockForUpdate()
                ->get();

            $targetRule = null;
            if ($ruleId !== null) {
                $targetRule = $lockedRules->firstWhere('id', $ruleId);
                if (! $targetRule instanceof ReferencePriceRule) {
                    abort(404);
                }
                Gate::authorize('update', $targetRule);

                $duplicate = $lockedRules->first(
                    fn (ReferencePriceRule $r): bool => $r->id !== $targetRule->id
                        && mb_strtolower($r->category_key) === mb_strtolower($categoryKey)
                );
                if ($duplicate instanceof ReferencePriceRule) {
                    return __('materials.reference_prices.rules.duplicate_key', ['key' => $categoryKey]);
                }
            } elseif ($formMode === 'create') {
                $duplicate = $lockedRules->first(
                    fn (ReferencePriceRule $r): bool => mb_strtolower($r->category_key) === mb_strtolower($categoryKey)
                );
                if ($duplicate instanceof ReferencePriceRule) {
                    return __('materials.reference_prices.rules.duplicate_key', ['key' => $categoryKey]);
                }
            } else {
                $targetRule = $lockedRules->first(
                    fn (ReferencePriceRule $r): bool => mb_strtolower($r->category_key) === mb_strtolower($categoryKey)
                );
                if ($targetRule instanceof ReferencePriceRule) {
                    Gate::authorize('update', $targetRule);
                }
            }

            $allRules = $lockedRules->map(fn (ReferencePriceRule $r): array => [
                'id' => $r->id,
                'category_key' => $r->category_key,
                'category_name' => $r->category_name,
                'dimension_min' => $r->dimension_min,
                'dimension_max' => $r->dimension_max,
                'sort_order' => $r->sort_order,
                'status' => $r->status,
            ])->all();

            if ($targetRule instanceof ReferencePriceRule) {
                foreach ($allRules as &$r) {
                    if ($r['id'] === $targetRule->id) {
                        $r['category_key'] = $categoryKey;
                        $r['category_name'] = $categoryName;
                        $r['dimension_min'] = $dimMin;
                        $r['dimension_max'] = $dimMax;
                        break;
                    }
                }
                unset($r);
            } else {
                $allRules[] = [
                    'id' => null,
                    'category_key' => $categoryKey,
                    'category_name' => $categoryName,
                    'dimension_min' => $dimMin,
                    'dimension_max' => $dimMax,
                    'sort_order' => count($allRules) + 1,
                    'status' => ReferencePriceRule::STATUS_ACTIVE,
                ];
            }

            $errors = $this->ruleImportService->validateRuleIntervals($allRules);
            if (! empty($errors)) {
                return implode('; ', $errors);
            }

            usort($allRules, fn (array $a, array $b): int => $a['dimension_min'] <=> $b['dimension_min']);

            $userId = $request->user()->id;
            $orderMap = [];

            foreach ($allRules as $idx => $r) {
                $sortOrder = $idx + 1;
                if ($r['id'] === null) {
                    $created = ReferencePriceRule::create([
                        'category_key' => $r['category_key'],
                        'category_name' => $r['category_name'],
                        'dimension_min' => $r['dimension_min'],
                        'dimension_max' => $r['dimension_max'],
                        'sort_order' => $sortOrder,
                        'status' => $r['status'],
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                    MasterDataAuditLog::record($created, MasterDataAuditLog::CREATED, null, $created->getAttributes(), $request->user());
                } else {
                    $orderMap[$r['id']] = $sortOrder;
                    if ($targetRule instanceof ReferencePriceRule && $r['id'] === $targetRule->id) {
                        $before = $targetRule->getAttributes();
                        $targetRule->update([
                            'category_key' => $categoryKey,
                            'category_name' => $categoryName,
                            'dimension_min' => $dimMin,
                            'dimension_max' => $dimMax,
                            'sort_order' => $sortOrder,
                            'created_by' => $targetRule->created_by ?? $userId,
                            'updated_by' => $userId,
                        ]);
                        MasterDataAuditLog::record($targetRule, MasterDataAuditLog::UPDATED, $before, $targetRule->getAttributes(), $request->user());
                    }
                }
            }

            foreach ($lockedRules as $existingRule) {
                if ($targetRule instanceof ReferencePriceRule && $existingRule->id === $targetRule->id) {
                    continue;
                }
                $expectedOrder = $orderMap[$existingRule->id] ?? null;
                if ($expectedOrder !== null && (int) $existingRule->sort_order !== $expectedOrder) {
                    $existingRule->timestamps = false;
                    $existingRule->forceFill(['sort_order' => $expectedOrder])->saveQuietly();
                    $existingRule->timestamps = true;
                }
            }

            return null;
        });

        if ($errorMessage !== null) {
            return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
                ->withInput()
                ->with('error', $errorMessage);
        }

        $this->resetRuleCaches();

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
            ->with('success', __('materials.reference_prices.rules.saved_success'));
    }

    public function toggleCategoryStatus(Request $request, ReferencePriceRule $rule): RedirectResponse
    {
        Gate::authorize('update', $rule);

        $errorMessage = DB::transaction(function () use ($request, $rule): ?string {
            $lockedRules = ReferencePriceRule::query()
                ->orderBy('dimension_min')
                ->lockForUpdate()
                ->get();

            $lockedRule = $lockedRules->firstWhere('id', $rule->id);
            if (! $lockedRule instanceof ReferencePriceRule) {
                abort(404);
            }

            $targetStatus = $lockedRule->status === ReferencePriceRule::STATUS_ACTIVE
                ? ReferencePriceRule::STATUS_INACTIVE
                : ReferencePriceRule::STATUS_ACTIVE;

            if ($targetStatus === ReferencePriceRule::STATUS_ACTIVE) {
                $activeRules = $lockedRules
                    ->filter(fn (ReferencePriceRule $r): bool => $r->id !== $lockedRule->id && $r->status === ReferencePriceRule::STATUS_ACTIVE)
                    ->map(fn (ReferencePriceRule $r): array => [
                        'category_key' => $r->category_key,
                        'dimension_min' => $r->dimension_min,
                        'dimension_max' => $r->dimension_max,
                        'status' => $r->status,
                    ])
                    ->values()
                    ->all();

                $activeRules[] = [
                    'category_key' => $lockedRule->category_key,
                    'dimension_min' => $lockedRule->dimension_min,
                    'dimension_max' => $lockedRule->dimension_max,
                    'status' => ReferencePriceRule::STATUS_ACTIVE,
                ];

                $errors = $this->ruleImportService->validateRuleIntervals($activeRules);
                if (! empty($errors)) {
                    return implode('; ', $errors);
                }
            }

            $before = $lockedRule->getAttributes();
            $lockedRule->update([
                'status' => $targetStatus,
                'updated_by' => $request->user()->id,
            ]);
            MasterDataAuditLog::record($lockedRule, MasterDataAuditLog::STATUS_CHANGED, $before, $lockedRule->getAttributes(), $request->user());

            return null;
        });

        if ($errorMessage !== null) {
            return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
                ->with('error', $errorMessage);
        }

        $this->resetRuleCaches();

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
            ->with('success', __('materials.reference_prices.rules.toggle_success'));
    }

    public function destroyCategory(Request $request, ReferencePriceRule $rule): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $rule);

        DB::transaction(function () use ($request, $rule): void {
            $lockedRules = ReferencePriceRule::query()
                ->orderBy('dimension_min')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedRule = $lockedRules->firstWhere('id', $rule->id);
            if (! $lockedRule instanceof ReferencePriceRule) {
                abort(404);
            }

            $before = $lockedRule->getAttributes();
            $deletedId = $lockedRule->id;
            $lockedRule->delete();

            MasterDataAuditLog::record($lockedRule, MasterDataAuditLog::DELETED, $before, null, $request->user());

            $remaining = $lockedRules->reject(fn (ReferencePriceRule $r): bool => $r->id === $deletedId)->values();
            foreach ($remaining as $index => $existingRule) {
                $expectedOrder = $index + 1;
                if ((int) $existingRule->sort_order !== $expectedOrder) {
                    $existingRule->timestamps = false;
                    $existingRule->forceFill(['sort_order' => $expectedOrder])->saveQuietly();
                    $existingRule->timestamps = true;
                }
            }
        });

        $this->resetRuleCaches();

        $message = __('materials.reference_prices.rules.deleted_success');

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->route($this->resolveIndexRouteName($request), ['tab' => 'rules'])
            ->with('success', $message);
    }

    private function resetRuleCaches(): void
    {
        if (app()->resolved(ReferencePriceRuleResolver::class)) {
            app(ReferencePriceRuleResolver::class)->reset();
        }
        if (app()->resolved(HistoricalReferencePriceService::class)) {
            app(HistoricalReferencePriceService::class)->clearMemo();
        }
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
