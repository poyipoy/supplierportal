<?php

namespace App\Http\Controllers\Supplier;

use App\Exports\QuotationImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\QuotationItemsImport;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\Materials\MaterialWeightCalculator;
use App\Services\NotificationService;
use App\Support\Materials\DimensionRange;
use App\Support\NotificationCategory;
use App\Support\SpreadsheetImportReader;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;
use Yajra\DataTables\Facades\DataTables;

class QuotationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MaterialWeightCalculator $weightCalculator,
    ) {}

    /**
     * Display open quotation periods.
     */
    public function index(Request $request)
    {
        $supplierId = auth()->id();

        $periods = Period::where('status', 'open')
            ->whereHas('purchaseRequisitions', function ($query) use ($supplierId) {
                $query->visibleToSupplier($supplierId);
            })
            ->orWhereHas('purchaseRequisitions.quotations', function ($query) use ($supplierId) {
                $query->where('supplier_id', $supplierId);
            })
            ->orderByDesc('year')
            ->orderByRaw('month IS NULL DESC')
            ->orderByDesc('month')
            ->get();

        // Count PRs for each period
        foreach ($periods as $period) {
            $activePrs = PurchaseRequisition::where('period_id', $period->id)
                ->whereIn('status', ['submitted', 'bidding'])
                ->visibleToSupplier($supplierId)
                ->get();

            $quotedPrIds = Quotation::where('supplier_id', $supplierId)
                ->whereHas('purchaseRequisition', function ($query) use ($period) {
                    $query->where('period_id', $period->id);
                })
                ->pluck('pr_id');

            $period->total_prs = $activePrs->pluck('id')
                ->merge($quotedPrIds)
                ->unique()
                ->count();

            // PRs that already have quotations from this supplier, including draft/submitted/rejected/accepted.
            $respondedCount = Quotation::where('supplier_id', auth()->id())
                ->whereIn('pr_id', $quotedPrIds)
                ->count();

            $rejectedCount = Quotation::where('supplier_id', auth()->id())
                ->whereIn('pr_id', $quotedPrIds)
                ->where('status', 'rejected')
                ->count();

            $period->responded_prs = $respondedCount;
            $period->rejected_prs = $rejectedCount;
            $period->unresponded_prs = $activePrs->filter(function ($pr) use ($supplierId) {
                return ! Quotation::where('supplier_id', $supplierId)
                    ->where('pr_id', $pr->id)
                    ->exists();
            })->count();
        }

        return view('supplier.quotations.index', compact('periods'));
    }

    /**
     * Display PRs for a selected period.
     */
    public function period(Request $request, $period_id)
    {
        $period = Period::findOrFail($period_id);
        $supplierId = auth()->id();

        $query = PurchaseRequisition::with(['items', 'quotations' => function ($query) use ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }])
            ->where('period_id', $period_id)
            ->visibleToSupplier($supplierId)
            ->where(function ($query) use ($supplierId) {
                $query->whereIn('status', ['submitted', 'bidding'])
                    ->orWhereHas('quotations', function ($q) use ($supplierId) {
                        $q->where('supplier_id', $supplierId);
                    });
            });

        if ($request->filled('pr_number')) {
            $query->where('pr_number', 'like', '%'.$request->pr_number.'%');
        }

        if ($request->filled('status')) {
            if ($request->status === 'unresponded') {
                $query->whereIn('status', ['submitted', 'bidding'])
                    ->whereDoesntHave('quotations', function ($q) use ($supplierId) {
                        $q->where('supplier_id', $supplierId);
                    });
            } else {
                $query->whereHas('quotations', function ($q) use ($request, $supplierId) {
                    $q->where('supplier_id', $supplierId)
                        ->where('status', $request->status);
                });
            }
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query->orderByDesc('updated_at'))
                ->addIndexColumn()
                ->addColumn('pr_number_display', fn ($pr) => $pr->pr_number ?? '-')
                ->addColumn('updated_date', fn ($pr) => $pr->updated_at->format('d M Y, H:i'))
                ->addColumn('item_count', fn ($pr) => $pr->items->count().' Item')
                ->addColumn('status_badge', function ($pr) {
                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';

                    return match ($status) {
                        'unresponded' => '<span class="ui-status-chip ui-status-chip--error">Not Responded</span>',
                        'draft' => '<span class="ui-status-chip ui-status-chip--neutral">Draft</span>',
                        'revision_requested' => '<span class="ui-status-chip ui-status-chip--warning">Revision Requested</span>',
                        'submitted' => '<span class="ui-status-chip ui-status-chip--success">Submitted ('.($quotation->submitted_at?->format('d M Y H:i') ?? '-').')</span>',
                        'accepted' => '<span class="ui-status-chip ui-status-chip--info">Accepted</span>',
                        'rejected' => '<span class="ui-status-chip ui-status-chip--error">Rejected</span>',
                        default => '<span class="ui-status-chip ui-status-chip--neutral">'.e(ucwords($status)).'</span>',
                    };
                })
                ->addColumn('action', function ($pr) {
                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';

                    $action = match ($status) {
                        'unresponded' => '<a href="'.route('supplier.quotations.create', $pr).'" class="ui-data-action ui-data-action--primary ui-focus-ring">Create Quotation</a>',
                        'draft' => '<a href="'.route('supplier.quotations.create', $pr).'" class="ui-data-action ui-data-action--primary ui-focus-ring">Continue</a>',
                        'revision_requested' => '<a href="'.route('supplier.quotations.create', $pr).'" class="ui-data-action ui-data-action--warning ui-focus-ring">Revise Quotation</a>',
                        default => $quotation ? '<a href="'.route('supplier.quotations.show', $quotation).'" class="ui-data-action ui-data-action--primary ui-focus-ring">View</a>' : '-',
                    };

                    return '<div class="d-inline-flex gap-1 justify-content-end flex-wrap">'.$action.'</div>';
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('supplier.quotations.period', compact('period'));
    }

    /**
     * Display the quotation create/edit form for a selected PR.
     */
    public function create($pr_id)
    {
        $pr = PurchaseRequisition::with(['items', 'invitedSuppliers'])->findOrFail($pr_id);

        if (! in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', 'This requisition is not available for quotation.');
        }

        if (! $pr->isVisibleToSupplier(auth()->id())) {
            abort(403, 'You are not invited to submit a quotation for this requisition.');
        }

        // Find an existing quotation.
        $quotation = Quotation::with('items.attachments')
            ->where('pr_id', $pr_id)
            ->where('supplier_id', auth()->id())
            ->first();

        // Final quotations are read-only; drafts and revision_requested quotations can be edited.
        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            return redirect()->route('supplier.quotations.show', $quotation)
                ->with('info', 'You have already submitted a quotation for this requisition.');
        }

        $currencyOptions = ExchangeRate::CURRENCIES;
        $supplierCurrency = old('currency', $quotation?->currency);
        if (! in_array($supplierCurrency, $currencyOptions, true)) {
            $supplierCurrency = '';
        }

        $supplierRate = $supplierCurrency ? ExchangeRate::latestRate($supplierCurrency) : null;
        $currencyRates = ExchangeRate::query()
            ->whereIn('currency', $currencyOptions)
            ->orderByDesc('valid_from')
            ->get()
            ->unique('currency')
            ->mapWithKeys(fn ($rate) => [$rate->currency => (float) $rate->rate_to_idr])
            ->all();

        return view('supplier.quotations.create', compact('pr', 'quotation', 'supplierCurrency', 'supplierRate', 'currencyOptions', 'currencyRates'));
    }

    public function importTemplate($pr_id)
    {
        $pr = $this->importableRequisition($pr_id);
        $safeNumber = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $pr->pr_number ?? ''), '_');
        $safeNumber = $safeNumber !== '' ? $safeNumber : 'PR_'.$pr->id;

        return Excel::download(
            new QuotationImportTemplateExport($pr->id),
            'template_import_quotation_'.$safeNumber.'.xlsx'
        );
    }

    public function importPreview(Request $request, $pr_id)
    {
        $pr = $this->importableRequisition($pr_id);
        $validator = Validator::make($request->all(), [
            'import_file' => [
                'required',
                File::types(['xlsx', 'xls', 'csv'])->max(10240),
                'extensions:xlsx,xls,csv',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(
                $this->importFailurePayload($validator->errors()->get('import_file')),
                422
            );
        }

        $import = new QuotationItemsImport($pr->items);

        try {
            SpreadsheetImportReader::import($import, $request->file('import_file'));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                $this->importFailurePayload(['The spreadsheet could not be read. Verify the file and try again.']),
                422
            );
        }

        return response()->json(
            $import->preview(),
            $import->hasFileErrors() ? 422 : 200
        );
    }

    /**
     * Save quotation as draft or submitted.
     */
    public function store(Request $request, $pr_id)
    {
        $pr = PurchaseRequisition::with('invitedSuppliers', 'items.materialMaster')->findOrFail($pr_id);

        if (! in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', 'This requisition is not available for quotation.');
        }

        if (! $pr->isVisibleToSupplier(auth()->id())) {
            abort(403, 'You are not invited to submit a quotation for this requisition.');
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:draft,submitted',
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'estimated_delivery' => 'required|date',
            'payment_terms' => 'required|string|max:100',
            'validity_period' => $request->action === 'submitted'
                ? 'required|date|after_or_equal:today'
                : 'nullable|date',
            'general_notes' => 'nullable|string',
            'items' => ['required', 'array', 'min:1', 'size:'.$pr->items->count()],
            'items.*.pr_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('pr_items', 'id')->where(fn ($query) => $query->where('pr_id', $pr->id)),
            ],
            'items.*.is_available' => ['sometimes', 'boolean'],
            // Price is conditional: explicit Not Available rows clear it.
            // Legacy payloads without is_available retain the old required
            // price contract through the validator callback below.
            'items.*.price_per_kg' => 'nullable|numeric',
            'items.*.notes' => 'nullable|string',
            // Keep the base rules permissive for Not Available rows; their
            // numeric offer fields are intentionally sanitized away.
            'items.*.available_qty' => 'nullable|integer',
            'items.*.available_thickness' => 'nullable|numeric',
            'items.*.available_d_inner' => 'nullable|numeric',
            'items.*.available_d_outer' => 'nullable|numeric',
            'items.*.available_width' => 'nullable|numeric',
            // The shared parser validates exact and range syntax after the
            // base validator; numeric-only validation would reject ranges.
            'items.*.available_length' => 'nullable',
            'items.*.available_length_input' => 'nullable',
            'items.*.offered_weight_per_unit' => 'nullable|numeric',
            'items.*.offered_weight_manual_override' => ['sometimes', 'boolean'],
            'items.*.mtc_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'currency.required' => 'Currency is required.',
            'currency.in' => 'Currency is invalid.',
            'payment_terms.required' => 'Payment terms are required.',
            'validity_period.required' => 'Quotation validity is required when submitting the final quotation.',
            'validity_period.after_or_equal' => 'Quotation validity cannot be earlier than today.',
            'items.*.mtc_file.mimes' => 'The MTC file must be PDF, JPG, JPEG, or PNG.',
            'items.*.mtc_file.max' => 'The MTC file size must not exceed 5MB.',
        ]);
        $validator->after(function ($validator) use ($request, $pr): void {
            $prItems = $pr->items->keyBy(fn (PrItem $item) => (int) $item->id);
            $rawItems = $request->input('items', []);
            $isSubmitted = $request->input('action') === 'submitted';

            foreach (is_array($rawItems) ? $rawItems : [] as $index => $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }

                $prItemId = filter_var($rawItem['pr_item_id'] ?? null, FILTER_VALIDATE_INT);
                /** @var PrItem|null $prItem */
                $prItem = $prItemId === false ? null : $prItems->get((int) $prItemId);
                if (! $prItem) {
                    continue;
                }

                $hasExplicitAvailability = array_key_exists('is_available', $rawItem)
                    || (array_key_exists('availability', $rawItem)
                        && trim((string) ($rawItem['availability'] ?? '')) !== '');
                $legacyPayload = ! $hasExplicitAvailability;
                $availabilityInput = array_key_exists('is_available', $rawItem)
                    ? $rawItem['is_available']
                    : ($rawItem['availability'] ?? true);
                $isAvailable = QuotationItem::normalizeAvailabilityState($availabilityInput);
                $rawAvailability = strtolower(trim((string) ($rawItem['availability'] ?? '')));
                if (array_key_exists('availability', $rawItem)
                    && ! array_key_exists('is_available', $rawItem)
                    && $rawAvailability !== ''
                    && ! in_array($rawAvailability, [
                        'available', 'yes', 'true', '1', 'not available', 'unavailable', 'no', 'false', '0',
                    ], true)) {
                    $validator->errors()->add(
                        "items.{$index}.availability",
                        'Availability must be Available or Not Available.'
                    );
                }
                $price = $rawItem['price_per_kg'] ?? null;

                if (! $isAvailable) {
                    continue;
                }

                if ($price === null || $price === '' || ! is_numeric($price) || (float) $price <= 0) {
                    $validator->errors()->add(
                        "items.{$index}.price_per_kg",
                        $legacyPayload
                            ? 'The price per kg field is required.'
                            : 'The price per kg field must be greater than zero for an available item.'
                    );
                }

                $offeredQty = $rawItem['available_qty'] ?? null;
                $offeredQty = $offeredQty === null || $offeredQty === '' ? null : (int) $offeredQty;

                if ($offeredQty !== null && $offeredQty < 1) {
                    $validator->errors()->add(
                        "items.{$index}.available_qty",
                        'The offered quantity must be at least 1 for an available item.'
                    );
                }

                // The cross-record ceiling always uses the persisted PR item.
                // Historical surplus rows are left untouched; new saves are
                // never allowed to create another surplus row.
                if ($offeredQty !== null && $offeredQty > $prItem->quantity_value) {
                    $validator->errors()->add(
                        "items.{$index}.available_qty",
                        'The offered quantity cannot exceed the requested quantity of '.$prItem->quantity_value.'. If you can supply more, enter the requested quantity and describe the additional capacity in Notes.'
                    );
                }

                if (! $legacyPayload) {
                    $rawWeight = $rawItem['offered_weight_per_unit'] ?? null;
                    if ($rawWeight !== null && $rawWeight !== '' && (! is_numeric($rawWeight) || (float) $rawWeight <= 0)) {
                        $validator->errors()->add(
                            "items.{$index}.offered_weight_per_unit",
                            'Offer KG/Unit must be greater than zero for an available item.'
                        );
                    }
                    foreach (PrItem::relevantDimensionFields($prItem->shape) as $field) {
                        $value = $rawItem['available_'.$field] ?? null;
                        if ($value !== null && $value !== '' && is_numeric($value) && (float) $value <= 0) {
                            $validator->errors()->add(
                                "items.{$index}.available_{$field}",
                                'Offered dimensions must be greater than zero for an available item.'
                            );
                        }
                    }
                    if ($prItem->shape === PrItem::SHAPE_HOLLOW
                        && is_numeric($rawItem['available_d_inner'] ?? null)
                        && is_numeric($rawItem['available_d_outer'] ?? null)
                        && (float) $rawItem['available_d_inner'] >= (float) $rawItem['available_d_outer']) {
                        $validator->errors()->add(
                            "items.{$index}.available_d_inner",
                            'Inner diameter must be smaller than outer diameter for a Hollow item.'
                        );
                    }
                }

                // Keep the historical form contract (which permits zero as
                // an incomplete dimension) but never allow a negative
                // numeric dimension through either payload shape.
                foreach (PrItem::relevantDimensionFields($prItem->shape) as $field) {
                    $value = $rawItem['available_'.$field] ?? null;
                    if ($value !== null && $value !== '' && is_numeric($value) && (float) $value < 0) {
                        $validator->errors()->add(
                            "items.{$index}.available_{$field}",
                            'Offered dimensions cannot be negative.'
                        );
                    }
                }

                $lengthInput = array_key_exists('available_length_input', $rawItem)
                    ? $rawItem['available_length_input']
                    : ($rawItem['available_length'] ?? null);
                if (($lengthInput === null || $lengthInput === '')
                    && ($rawItem['available_length_min'] ?? null) !== null
                    && ($rawItem['available_length_max'] ?? null) !== null) {
                    $lengthInput = (string) $rawItem['available_length_min'].'-'.(string) $rawItem['available_length_max'];
                }
                $hasLengthInput = ! ($lengthInput === null || (is_string($lengthInput) && trim($lengthInput) === ''));
                $length = DimensionRange::parse($lengthInput);
                if ($hasLengthInput && $length === null) {
                    $validator->errors()->add(
                        "items.{$index}.available_length_input",
                        'Length must be a positive number or a valid range such as 2300-2500.'
                    );
                }

                $offer = $this->resolveOfferedWeight($prItem, $rawItem, $length, $legacyPayload);
                if ($isSubmitted && ! $legacyPayload) {
                    if ($offeredQty === null) {
                        $validator->errors()->add(
                            "items.{$index}.available_qty",
                            'The offered quantity is required for an available item when submitting the final quotation.'
                        );
                    }
                    if ($offer['weight'] === null) {
                        $validator->errors()->add(
                            "items.{$index}.offered_weight_per_unit",
                            $offer['error'] ?? 'Offer KG/Unit is required for an available item when submitting the final quotation.'
                        );
                    }
                    if ($price === null || $price === '') {
                        $validator->errors()->add(
                            "items.{$index}.price_per_kg",
                            'The price per kg field is required for an available item when submitting the final quotation.'
                        );
                    }
                }

            }
        });

        $validated = $validator->validate();
        $supplierCurrency = $validated['currency'];

        $quotation = Quotation::where('pr_id', $pr_id)
            ->where('supplier_id', auth()->id())
            ->first();

        $wasRevisionRequested = $quotation?->status === Quotation::STATUS_REVISION_REQUESTED;

        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            return redirect()->route('supplier.quotations.show', $quotation)
                ->with('error', 'This quotation has already been submitted and cannot be changed.');
        }

        try {
            DB::beginTransaction();

            $nextStatus = $request->action === 'submitted'
                ? Quotation::STATUS_SUBMITTED
                : ($wasRevisionRequested ? Quotation::STATUS_REVISION_REQUESTED : Quotation::STATUS_DRAFT);

            // Calculate the exchange rate snapshot when submitted.
            $exchangeRateId = $quotation?->currency === $supplierCurrency
                ? $quotation?->exchange_rate_id
                : null;
            if ($request->action === 'submitted') {
                $rate = ExchangeRate::latestRate($supplierCurrency);
                if (! $rate) {
                    DB::rollBack();

                    return back()
                        ->withInput()
                        ->with('error', 'Exchange rate for '.$supplierCurrency.' is not available yet. Contact Admin before submitting the final quotation.');
                }

                $exchangeRateId = $rate->id;
            }

            // Recheck the exact PR item set under the transaction lock.  The
            // request was validated against the pre-transaction snapshot, so
            // this prevents a concurrent PR edit from silently producing an
            // incomplete quotation response set.
            $currentPrItemIds = PrItem::query()
                ->where('pr_id', $pr->id)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $validatedPrItemIds = collect($validated['items'])
                ->pluck('pr_item_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            if ($currentPrItemIds !== $validatedPrItemIds) {
                throw new \RuntimeException('The requisition items changed while the quotation was being saved. Please reload and try again.');
            }

            if (! $quotation) {
                $quotation = Quotation::create([
                    'pr_id' => $pr_id,
                    'supplier_id' => auth()->id(),
                    'currency' => $supplierCurrency,
                    'status' => $nextStatus,
                    'submitted_at' => $request->action === 'submitted' ? now() : null,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $request->estimated_delivery,
                    'payment_terms' => $validated['payment_terms'],
                    'validity_period' => $request->validity_period,
                    'general_notes' => $request->general_notes,
                ]);
            } else {
                $quotation->update([
                    'currency' => $supplierCurrency,
                    'status' => $nextStatus,
                    'submitted_at' => $request->action === 'submitted' ? now() : $quotation->submitted_at,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $request->estimated_delivery,
                    'payment_terms' => $validated['payment_terms'],
                    'validity_period' => $request->validity_period,
                    'general_notes' => $request->general_notes,
                ]);
            }

            $existingItemAttachments = $quotation->items()
                ->with('attachments')
                ->get()
                ->keyBy('pr_item_id')
                ->map(fn ($item) => $item->attachments);

            $quotation->items()->delete();

            // Save the validated, exact PR item set without trusting request indexes or IDs.
            // Re-query each item so a long-lived/cached PR relation can never
            // produce an amount from stale weight or quantity values.
            foreach ($validated['items'] as $index => $itemData) {
                /** @var PrItem $prItem */
                $prItem = PrItem::query()
                    ->with('materialMaster')
                    ->whereKey((int) $itemData['pr_item_id'])
                    ->where('pr_id', $pr->id)
                    ->firstOrFail();
                // Rebuild from the fresh PR row inside the transaction. The
                // pre-validation map protects request UX, but persisted
                // quantity/weight must come from current authoritative data.
                $rawItem = $request->input("items.{$index}", $itemData);
                $rawItem = is_array($rawItem) ? $rawItem : $itemData;
                $hasExplicitAvailability = array_key_exists('is_available', $rawItem)
                    || (array_key_exists('availability', $rawItem)
                        && trim((string) ($rawItem['availability'] ?? '')) !== '');
                $legacyPayload = ! $hasExplicitAvailability;
                $availabilityInput = array_key_exists('is_available', $rawItem)
                    ? $rawItem['is_available']
                    : ($rawItem['availability'] ?? true);
                $isAvailable = QuotationItem::normalizeAvailabilityState($availabilityInput);
                $priceValue = $isAvailable && (($rawItem['price_per_kg'] ?? null) !== null && ($rawItem['price_per_kg'] ?? '') !== '')
                    ? (float) $rawItem['price_per_kg']
                    : null;
                $lengthInput = array_key_exists('available_length_input', $rawItem)
                    ? $rawItem['available_length_input']
                    : ($rawItem['available_length'] ?? null);
                if (($lengthInput === null || $lengthInput === '')
                    && ($rawItem['available_length_min'] ?? null) !== null
                    && ($rawItem['available_length_max'] ?? null) !== null) {
                    $lengthInput = (string) $rawItem['available_length_min'].'-'.(string) $rawItem['available_length_max'];
                }
                $length = DimensionRange::parse($lengthInput);
                $availability = QuotationItem::sanitizeAvailabilityData($rawItem, $prItem);
                $offeredQty = $rawItem['available_qty'] ?? null;
                $offeredQty = $offeredQty === null || $offeredQty === '' ? null : (int) $offeredQty;
                $offer = $isAvailable
                    ? $this->resolveOfferedWeight($prItem, $rawItem, $length, $legacyPayload)
                    : ['weight' => null, 'source' => null, 'error' => null];
                if ($isAvailable && $offeredQty !== null && $offeredQty > $prItem->quantity_value) {
                    throw new \RuntimeException('The offered quantity no longer fits the current requested quantity. Please reload the requisition and try again.');
                }
                if ($request->action === 'submitted'
                    && $isAvailable
                    && ! $legacyPayload
                    && ($offeredQty === null || $offer['weight'] === null || $priceValue === null)) {
                    throw new \RuntimeException('The available offer changed while it was being saved. Please reload the requisition and complete the offer again.');
                }
                $availability['offered_weight_per_unit'] = $offer['weight'];
                $availability['offered_weight_source'] = $offer['source'];
                $offerTotalWeight = $isAvailable && $offeredQty !== null && $offer['weight'] !== null
                    ? round($offeredQty * $offer['weight'], 4, PHP_ROUND_HALF_UP)
                    : null;
                $amount = ! $isAvailable
                    ? 0.0
                    : ($legacyPayload
                        ? (QuotationItem::calculateRequestedAmount($prItem, $priceValue) ?? 0.0)
                        : ($offerTotalWeight === null
                            ? 0.0
                            : QuotationItem::calculateOfferAmount($offerTotalWeight, $priceValue)));
                $offer = [
                    ...$availability,
                    'price_per_kg' => $priceValue,
                    'offer_total_weight' => $offerTotalWeight,
                    'amount' => $amount,
                ];
                $offerFields = $offer;
                unset($offerFields['price_per_kg'], $offerFields['amount'], $offerFields['offer_total_weight']);

                $quotationItem = $quotation->items()->create([
                    'pr_item_id' => $prItem->id,
                    'price_per_kg' => $offer['price_per_kg'],
                    'amount' => $offer['amount'],
                    'notes' => $itemData['notes'] ?? null,
                    ...$offerFields,
                ]);

                $mtcFile = $request->file("items.{$index}.mtc_file");
                if ($mtcFile && $mtcFile->isValid()) {
                    $this->storeMtcAttachment($quotationItem, $mtcFile);
                } elseif ($existingItemAttachments->has($prItem->id)) {
                    foreach ($existingItemAttachments->get($prItem->id) as $attachment) {
                        $attachment->update([
                            'attachable_id' => $quotationItem->id,
                        ]);
                    }
                }
            }

            // Move the PR to bidding when a quotation is submitted.
            if ($request->action === 'submitted' && $pr->status === 'submitted') {
                $pr->update(['status' => 'bidding']);
            }

            DB::commit();

            // Notify purchasing when quotation submitted
            if ($request->action === 'submitted') {
                $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
                $title = $wasRevisionRequested ? 'Revised Quotation Received' : 'New Quotation Received';
                $message = $wasRevisionRequested
                    ? 'Supplier :name resubmitted a revised quotation for PR :pr_number'
                    : 'Supplier :name submitted a quotation for PR :pr_number';

                $event = $wasRevisionRequested ? 'quotation.revised' : 'quotation.submitted';
                $submittedKey = $quotation->submitted_at?->format('YmdHis.u') ?? $quotation->updated_at?->format('YmdHis.u');
                $this->notifications->send(
                    $purchasingUsers,
                    $event,
                    "{$event}:{$quotation->id}:{$submittedKey}",
                    $title,
                    $message,
                    route('purchasing.requisitions.show', $pr, absolute: false),
                    'mail-check text-success',
                    [
                        'category' => NotificationCategory::QUOTATION,
                        'quotation_id' => $quotation->id,
                        'pr_id' => $pr->id,
                        'pr_number' => $pr->pr_number,
                    ],
                    ['name' => auth()->user()->name, 'pr_number' => $pr->pr_number],
                );
            }

            $msg = $request->action === 'submitted'
                ? ($wasRevisionRequested ? 'Revised quotation has been resubmitted.' : 'Quotation successfully sent.')
                : ($wasRevisionRequested ? 'Revised quotation draft successfully saved.' : 'Draft quotation successfully saved.');

            return redirect()->route('supplier.quotations.period', $pr->period_id)->with('success', $msg);

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Failed to save quotation: '.$e->getMessage());
        }
    }

    /**
     * Display quotation details.
     */
    public function show($id)
    {
        $quotation = Quotation::with(['items.prItem', 'items.attachments', 'purchaseRequisition.period', 'exchange_rate'])
            ->findOrFail($id);

        Gate::authorize('view', $quotation);

        $conversation = Conversation::where('conversable_type', PurchaseRequisition::class)
            ->where('conversable_id', $quotation->pr_id)
            ->where('supplier_user_id', auth()->id())
            ->first();

        return view('supplier.quotations.show', compact('quotation', 'conversation'));
    }

    /**
     * Resolve supplier-side KG/unit without changing the persisted PR item.
     * Exact geometry uses the existing material calculator; ranges never use a
     * midpoint/min/max approximation and therefore require supplier weight.
     *
     * @return array{weight: ?float, source: ?string, error: ?string}
     */
    private function resolveOfferedWeight(
        PrItem $prItem,
        array $item,
        ?DimensionRange $length,
        bool $legacyPayload,
    ): array {
        $rawWeight = $item['offered_weight_per_unit'] ?? null;
        $hasWeight = $rawWeight !== null && $rawWeight !== '' && is_numeric($rawWeight) && (float) $rawWeight > 0;
        $manualOverride = $this->booleanInput($item['offered_weight_manual_override'] ?? false);

        if ($length?->isRange()) {
            if ($hasWeight) {
                return [
                    'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                    'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                    'error' => null,
                ];
            }

            return [
                'weight' => $legacyPayload ? (float) $prItem->weight_needed : null,
                'source' => null,
                'error' => 'A length range requires a supplier-provided Offer KG/Unit marked as estimated.',
            ];
        }

        $sanitized = QuotationItem::sanitizeAvailabilityData($item, $prItem);
        $dimensions = [];
        foreach (PrItem::relevantDimensionFields($prItem->shape) as $field) {
            $dimensions[$field] = $field === 'length'
                ? $length?->exact
                : ($sanitized['available_'.$field] ?? null);
        }
        $hasCompleteGeometry = collect(PrItem::relevantDimensionFields($prItem->shape))
            ->every(fn (string $field) => isset($dimensions[$field]) && is_numeric($dimensions[$field]) && (float) $dimensions[$field] > 0);

        if ($manualOverride) {
            if ($hasWeight) {
                return [
                    'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                    'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                    'error' => null,
                ];
            }

            return [
                'weight' => null,
                'source' => null,
                'error' => 'Offer KG/Unit must be greater than zero when manual override is selected.',
            ];
        }

        if ($hasCompleteGeometry && $prItem->materialMaster) {
            $calculation = $this->weightCalculator->calculate(
                $prItem->materialMaster,
                $prItem->shape,
                $dimensions,
                1,
            );

            if ($calculation->isCalculated()
                && $calculation->unitKg !== null
                && (float) $calculation->unitKg > 0) {
                if ($hasWeight && abs((float) $rawWeight - (float) $calculation->unitKg) > QuotationItem::AVAILABILITY_TOLERANCE) {
                    return [
                        'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                        'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                        'error' => null,
                    ];
                }

                return [
                    'weight' => (float) $calculation->unitKg,
                    'source' => QuotationItem::OFFER_WEIGHT_SOURCE_AUTO,
                    'error' => null,
                ];
            }
        }

        if ($hasWeight) {
            return [
                'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                'error' => null,
            ];
        }

        if ($legacyPayload) {
            // Pre-revision forms did not submit Offer KG/Unit. Retain their
            // requested-weight amount semantics and render safely.
            return [
                'weight' => (float) $prItem->weight_needed,
                'source' => null,
                'error' => null,
            ];
        }

        return [
            'weight' => null,
            'source' => null,
            'error' => 'Complete the offered dimensions or provide Offer KG/Unit before saving this available item.',
        ];
    }

    private function booleanInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ?? false;
    }

    private function storeMtcAttachment(QuotationItem $quotationItem, UploadedFile $file): void
    {
        // Use getPathname() to avoid getRealPath() returning false on Windows.
        $fileName = $file->hashName();
        $path = 'attachments/'.now()->format('Y/m').'/'.$fileName;

        $stream = fopen($file->getPathname(), 'r');
        if ($stream) {
            Storage::disk('private')->put($path, $stream);
            fclose($stream);

            $quotationItem->attachments()->create([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getMimeType(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    private function importableRequisition($prId): PurchaseRequisition
    {
        $supplier = auth()->user();

        if (! $supplier || ! $supplier->is_active) {
            abort(403, 'This supplier account is not active.');
        }

        $supplierId = (int) $supplier->id;
        $pr = PurchaseRequisition::with([
            'items',
            'invitedSuppliers',
            'quotations' => fn ($query) => $query->where('supplier_id', $supplierId),
        ])->findOrFail($prId);

        if (! in_array($pr->status, ['submitted', 'bidding'], true)) {
            abort(403, 'This requisition is not available for quotation import.');
        }

        $isVisible = PurchaseRequisition::query()
            ->whereKey($pr->id)
            ->visibleToSupplier($supplierId)
            ->exists();

        if (! $isVisible) {
            abort(403, 'You are not invited to submit a quotation for this requisition.');
        }

        $quotation = $pr->quotations->first();

        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            abort(403, 'This quotation can no longer be imported or changed.');
        }

        return $pr;
    }

    private function importFailurePayload(array $messages): array
    {
        return [
            'success' => false,
            'rows' => [],
            'warnings' => [],
            'summary' => [
                'total' => 0,
                'valid' => 0,
                'invalid' => 0,
            ],
            'errors' => collect($messages)
                ->map(fn (string $message) => [
                    'row' => null,
                    'column' => 'import_file',
                    'message' => $message,
                ])
                ->values()
                ->all(),
        ];
    }
}
