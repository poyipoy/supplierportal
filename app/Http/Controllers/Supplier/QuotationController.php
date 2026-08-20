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
use App\Services\NotificationService;
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
    public function __construct(private readonly NotificationService $notifications) {}

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
                        'unresponded' => '<span class="badge bg-danger">Not Responded</span>',
                        'draft' => '<span class="badge bg-secondary">Draft</span>',
                        'revision_requested' => '<span class="badge bg-warning text-dark">Revision Requested</span>',
                        'submitted' => '<span class="badge bg-success">Submitted ('.($quotation->submitted_at?->format('d M Y H:i') ?? '-').')</span>',
                        'accepted' => '<span class="badge bg-primary">Accepted</span>',
                        'rejected' => '<span class="badge bg-dark">Rejected</span>',
                        default => '<span class="badge bg-secondary">'.ucwords($status).'</span>',
                    };
                })
                ->addColumn('action', function ($pr) {
                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';

                    $action = match ($status) {
                        'unresponded' => '<a href="'.route('supplier.quotations.create', $pr).'" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square me-1"></i> Create Quotation</a>',
                        'draft' => '<a href="'.route('supplier.quotations.create', $pr).'" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Continue</a>',
                        'revision_requested' => '<a href="'.route('supplier.quotations.create', $pr).'" class="btn btn-sm btn-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i> Revise Quotation</a>',
                        default => $quotation ? '<a href="'.route('supplier.quotations.show', $quotation).'" class="btn btn-sm btn-outline-success"><i class="bi bi-eye me-1"></i> View</a>' : '-',
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
        $pr = PurchaseRequisition::with('invitedSuppliers', 'items')->findOrFail($pr_id);

        if (! in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', 'This requisition is not available for quotation.');
        }

        if (! $pr->isVisibleToSupplier(auth()->id())) {
            abort(403, 'You are not invited to submit a quotation for this requisition.');
        }

        $validated = $request->validate([
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
            'items.*.price_per_kg' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
            'items.*.available_qty' => 'nullable|integer|min:1',
            'items.*.available_thickness' => 'nullable|numeric|min:0',
            'items.*.available_d_inner' => 'nullable|numeric|min:0',
            'items.*.available_d_outer' => 'nullable|numeric|min:0',
            'items.*.available_width' => 'nullable|numeric|min:0',
            'items.*.available_length' => 'nullable|numeric|min:0',
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
                    ->whereKey((int) $itemData['pr_item_id'])
                    ->where('pr_id', $pr->id)
                    ->firstOrFail();
                $amount = QuotationItem::calculateAmount($prItem, $itemData['price_per_kg']);

                $quotationItem = $quotation->items()->create([
                    'pr_item_id' => $prItem->id,
                    'price_per_kg' => $itemData['price_per_kg'],
                    'amount' => $amount,
                    'notes' => $itemData['notes'] ?? null,
                    ...QuotationItem::sanitizeAvailabilityData($itemData, $prItem),
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
                    'bi-envelope-check text-success',
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
