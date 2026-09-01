<?php

namespace App\Http\Controllers\Purchasing;

use App\Exports\PrImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePurchaseRequisitionRequest;
use App\Imports\PrItemsImport;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Materials\MaterialResolver;
use App\Services\Materials\PurchaseRequisitionItemSynchronizer;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\NumberFormat;
use App\Support\PurchasingNavigation;
use App\Support\SpreadsheetImportReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;
use Yajra\DataTables\Facades\DataTables;

class PurchaseRequisitionController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PurchaseRequisitionItemSynchronizer $itemSynchronizer,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = PurchaseRequisition::query()
                ->select([
                    'purchase_requisitions.id',
                    'purchase_requisitions.period_id',
                    'purchase_requisitions.created_by',
                    'purchase_requisitions.pr_number',
                    'purchase_requisitions.status',
                    'purchase_requisitions.created_at',
                ])
                ->with([
                    'period:id,name,month,year',
                    'creator:id,name',
                ])
                ->withCount(['items', 'invitedSuppliers'])
                ->selectSub(
                    PrItem::query()
                        ->selectRaw('COALESCE(SUM(COALESCE(pr_items.weight_needed, 0) * CASE WHEN pr_items.quantity IS NULL OR pr_items.quantity < 1 THEN 1 ELSE pr_items.quantity END), 0)')
                        ->whereColumn('pr_items.pr_id', 'purchase_requisitions.id'),
                    'total_kg',
                )
                ->selectSub(
                    Quotation::query()
                        ->selectRaw('COUNT(DISTINCT quotations.supplier_id)')
                        ->whereColumn('quotations.pr_id', 'purchase_requisitions.id')
                        ->whereNotNull('quotations.submitted_at')
                        ->whereNull('quotations.deleted_at'),
                    'submitted_supplier_count',
                )
                ->orderBy('created_at', 'desc');

            if ($request->filled('period_id')) {
                $query->where('period_id', $request->period_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('pr_number_display', fn ($pr) => $pr->pr_number ?? '-')
                ->addColumn('period_name', fn ($pr) => $pr->period->display_label ?? '-')
                ->addColumn('creator_name', fn ($pr) => $pr->creator->name ?? '-')
                ->addColumn('item_count', fn ($pr) => $pr->items_count.' Item')
                ->addColumn('total_kg', fn ($pr) => NumberFormat::maxDecimals($pr->total_kg).' kg')
                ->addColumn('supplier_count', fn ($pr) => $pr->invited_suppliers_count)
                ->addColumn('status_badge', function ($pr) {
                    $tone = match ($pr->status) {
                        'draft' => 'neutral',
                        'submitted' => 'info',
                        'rejected' => 'error',
                        'bidding' => 'warning',
                        'completed' => 'success',
                        default => 'neutral'
                    };
                    $statusLabel = match ($pr->status) {
                        'draft' => 'Draft',
                        'submitted' => 'Submitted',
                        'rejected' => 'Rejected',
                        'bidding' => 'Bidding',
                        'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $pr->status)),
                    };

                    $responseChip = '';
                    if ($pr->status === 'bidding') {
                        $count = (int) ($pr->submitted_supplier_count ?? 0);
                        $responseChip = ' <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums ms-1" title="'.e($count.' supplier quotations submitted').'" aria-label="'.e($count.' supplier quotations submitted').'">'.$count.'</span>';
                    }

                    return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'.e($statusLabel).'</span>'.$responseChip;
                })
                ->addColumn('created_date', fn ($pr) => $pr->created_at->format('d M Y, H:i'))
                ->addColumn('action', function ($pr) {
                    $viewUrl = PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr);
                    $editUrl = PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr);
                    $canEdit = $pr->created_by === auth()->id() && in_array($pr->status, ['draft', 'rejected'], true);
                    $secondaryActions = [];

                    if ($pr->created_by === auth()->id() && $pr->status === 'draft') {
                        $primaryAction = '<form action="'.route('purchasing.requisitions.submit', $pr).'" method="POST" class="draft-submit-form tw-m-0">'
                            .csrf_field()
                            .method_field('PUT')
                            .'<button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-submit-draft" aria-label="Submit draft '.e($pr->pr_number).'">'
                            .'Submit'
                            .'</button></form>';
                        $secondaryActions[] = '<li><a href="'.$viewUrl.'" class="dropdown-item">View details</a></li>';
                        $secondaryActions[] = '<li><a href="'.$editUrl.'" class="dropdown-item">Edit draft</a></li>';
                    } elseif ($canEdit) {
                        $primaryAction = '<a href="'.$editUrl.'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="Edit '.e($pr->pr_number).'">Edit</a>';
                        $secondaryActions[] = '<li><a href="'.$viewUrl.'" class="dropdown-item">View details</a></li>';
                    } else {
                        $primaryAction = '<a href="'.$viewUrl.'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="View '.e($pr->pr_number).'">View</a>';
                    }

                    if ($canEdit) {
                        $secondaryActions[] = '<li><form action="'.route('purchasing.requisitions.destroy', $pr).'" method="POST" class="delete-form">'.csrf_field().method_field('DELETE').'<button type="button" class="dropdown-item text-danger btn-delete">Delete requisition</button></form></li>';
                    }

                    if ($secondaryActions === []) {
                        return '<div class="d-inline-flex justify-content-end">'.$primaryAction.'</div>';
                    }

                    return '<div class="d-inline-flex align-items-center justify-content-end gap-1">'.$primaryAction
                        .'<div class="dropdown"><button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for '.e($pr->pr_number).'">More</button>'
                        .'<ul class="dropdown-menu dropdown-menu-end">'.implode('', $secondaryActions).'</ul></div></div>';
                })
                ->rawColumns(['status_badge', 'action'])
                ->ignoreSelectsInCountQuery()
                ->only([
                    'DT_RowIndex',
                    'pr_number_display',
                    'period_name',
                    'creator_name',
                    'supplier_count',
                    'item_count',
                    'total_kg',
                    'status_badge',
                    'created_date',
                    'action',
                ])
                ->make(true);
        }

        $periods = Period::orderByDesc('year')->orderByRaw('month IS NULL DESC')->orderByDesc('month')->get();

        return view('purchasing.pr.index', compact('periods'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $periods = Period::where('status', 'open')->orderByDesc('year')->orderByRaw('month IS NULL DESC')->orderByDesc('month')->get();
        $suppliers = User::where('role', 'supplier')
            ->where('is_active', true)
            ->with('supplier')
            ->orderBy('name')
            ->get();

        if ($periods->isEmpty()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'No active open period. Please contact Admin to open a period.');
        }

        return view('purchasing.pr.create', compact('periods', 'suppliers'));
    }

    public function importTemplate()
    {
        return Excel::download(new PrImportTemplateExport, 'template_import_pr.xlsx');
    }

    public function importPreview(Request $request)
    {
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

        $import = app(PrItemsImport::class);

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
     * Submit an existing draft requisition from the requisition list.
     */
    public function submitDraft(string $id)
    {
        $pr = PurchaseRequisition::findOrFail($id);

        if ($pr->created_by !== auth()->id() || $pr->status !== 'draft') {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'Only your draft requisitions can be submitted from this list.');
        }

        try {
            DB::transaction(function () use ($pr) {
                $this->itemSynchronizer->reprocessForSubmission($pr, auth()->id());
                $pr->update([
                    'pr_number' => $pr->pr_number ?: PurchaseRequisition::generatePrNumber(),
                    'status' => 'submitted',
                ]);
            });

            $this->notifyAdminsOfSubmission($pr);

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('success', 'Purchase Requisition successfully submitted!');
        } catch (ValidationException $exception) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->withErrors($exception->errors())
                ->with('error', 'Complete every material before submitting this requisition.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'A system error occurred while submitting the purchase requisition.');
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SavePurchaseRequisitionRequest $request)
    {
        $validated = $request->validated();

        try {
            $pr = DB::transaction(function () use ($validated) {
                $pr = PurchaseRequisition::create([
                    'period_id' => $validated['period_id'],
                    'created_by' => auth()->id(),
                    'pr_number' => $validated['action'] === 'submitted' ? PurchaseRequisition::generatePrNumber() : null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => $validated['action'],
                ]);

                $this->itemSynchronizer->sync(
                    $pr,
                    $validated['items'],
                    $validated['action'] === 'submitted',
                    auth()->id(),
                );
                $this->syncInvitedSuppliers($pr, $this->supplierIdsFromValidated($validated));

                return $pr;
            });

            if ($validated['action'] === 'submitted') {
                $this->notifyAdminsOfSubmission($pr);
            }

            $message = $validated['action'] === 'submitted'
                ? 'Purchase Requisition successfully submitted!'
                : 'Purchase Requisition successfully saved as draft.';

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))->with('success', $message);

        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'A system error occurred while saving the requisition.');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $pr = PurchaseRequisition::with([
            'period',
            'items',
            'invitedSuppliers.supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.exchange_rate',
            'creator',
        ])->findOrFail($id);

        // Quotation exposes total_amount/total_idr accessors backed by the
        // stored Offer Amount (with the model's documented legacy fallback).
        $quotations = $pr->quotations;

        $lowestTotalIdr = $quotations
            ->pluck('total_idr')
            ->filter(fn ($total) => $total !== null && $total > 0)
            ->min();

        $submittedQuotationCount = Quotation::query()
            ->where('pr_id', $pr->id)
            ->whereNotNull('submitted_at')
            ->whereNull('deleted_at')
            ->distinct('supplier_id')
            ->count('supplier_id');
        $totalKg = $pr->items->sum(fn ($item) => $item->total_weight);

        return view('purchasing.pr.show', compact(
            'pr',
            'quotations',
            'lowestTotalIdr',
            'submittedQuotationCount',
            'totalKg',
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $pr = PurchaseRequisition::with(['period', 'items', 'invitedSuppliers.supplier'])->findOrFail($id);

        if ($pr->created_by !== auth()->id() || ! in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'You cannot edit this requisition.');
        }

        $resolver = app(MaterialResolver::class);
        $materialIndex = $resolver->activeIndex();
        foreach ($pr->items as $item) {
            if ($item->material_master_id === null && ! empty($item->material_name)) {
                $matched = $resolver->resolveExact($item->material_name, $materialIndex);
                if (! $matched) {
                    $stripped = preg_replace('/[\s\-_]+(F|R|H|FLAT|ROUND|HOLLOW)$/i', '', trim($item->material_name));
                    $matched = $resolver->resolveExact($stripped, $materialIndex);
                    if (! $matched) {
                        $nospace = str_replace(' ', '', $stripped);
                        $matched = $resolver->resolveExact($nospace, $materialIndex);
                    }
                }
                if ($matched) {
                    $item->material_master_id = $matched->id;
                }
            }
        }

        $periods = Period::where('status', 'open')
            ->orWhere('id', $pr->period_id) // Allow keeping current period even if closed
            ->orderByDesc('year')->orderByRaw('month IS NULL DESC')->orderByDesc('month')->get();

        $suppliers = User::where('role', 'supplier')
            ->where('is_active', true)
            ->with('supplier')
            ->orderBy('name')
            ->get();

        return view('purchasing.pr.edit', compact('pr', 'periods', 'suppliers'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SavePurchaseRequisitionRequest $request, string $id)
    {
        $pr = PurchaseRequisition::findOrFail($id);

        if ($pr->created_by !== auth()->id() || ! in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'You cannot edit this requisition.');
        }

        $validated = $request->validated();

        try {
            DB::transaction(function () use ($pr, $validated, $request) {
                $this->itemSynchronizer->sync(
                    $pr,
                    $validated['items'],
                    $validated['action'] === 'submitted',
                    auth()->id(),
                );

                $pr->update([
                    'period_id' => $validated['period_id'],
                    'pr_number' => ($validated['action'] === 'submitted' && ! $pr->pr_number)
                        ? PurchaseRequisition::generatePrNumber()
                        : $pr->pr_number,
                    'notes' => $validated['notes'] ?? null,
                    'status' => $validated['action'],
                ]);

                if ($request->boolean('supplier_selection_present') || $request->has('supplier_id') || $request->has('supplier_ids')) {
                    $this->syncInvitedSuppliers($pr, $this->supplierIdsFromValidated($validated));
                }
            });

            if ($validated['action'] === 'submitted') {
                $this->notifyAdminsOfSubmission($pr);
            }

            $message = $validated['action'] === 'submitted'
                ? 'Purchase Requisition successfully submitted!'
                : 'Draft purchase requisition successfully updated.';

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))->with('success', $message);

        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'A system error occurred while saving the requisition.');
        }
    }

    private function supplierIdsFromValidated(array $validated): array
    {
        if (! empty($validated['supplier_id'])) {
            return [(int) $validated['supplier_id']];
        }

        return collect($validated['supplier_ids'] ?? [])
            ->filter()
            ->map(fn ($supplierId) => (int) $supplierId)
            ->all();
    }

    private function syncInvitedSuppliers(PurchaseRequisition $pr, array $supplierIds): void
    {
        $syncData = collect($supplierIds)
            ->filter()
            ->unique()
            ->mapWithKeys(fn ($supplierId) => [
                (int) $supplierId => ['invited_at' => now()],
            ])
            ->all();

        $pr->invitedSuppliers()->sync($syncData);
    }

    private function notifyAdminsOfSubmission(PurchaseRequisition $pr): void
    {
        $admins = User::where('role', 'admin')->where('is_active', true)->get();

        $this->notifications->send(
            $admins,
            'pr.submitted',
            'pr.submitted:'.$pr->id.':'.($pr->updated_at?->format('YmdHis.u') ?? 'initial'),
            'New Purchase Requisition',
            'New PR :pr_number has been submitted by :name',
            route('admin.requisitions.show', $pr, absolute: false),
            'clipboard-plus text-primary',
            [
                'category' => NotificationCategory::QUOTATION,
                'pr_id' => $pr->id,
                'pr_number' => $pr->pr_number,
            ],
            [
                'pr_number' => $pr->pr_number ?? '-',
                'name' => auth()->user()->name,
            ],
        );
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

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $pr = PurchaseRequisition::findOrFail($id);

        if ($pr->created_by !== auth()->id() || ! in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'Purchase Requisition cannot be deleted because it has been processed.');
        }

        $hasReferencedItems = $pr->items()
            ->where(function ($query) {
                $query->whereHas('quotationItems')->orWhereHas('qcItems');
            })
            ->exists();
        if ($hasReferencedItems) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'This requisition contains material referenced by a quotation or QC record and cannot be deleted.');
        }

        try {
            DB::beginTransaction();
            $pr->items()->delete();
            $pr->delete();
            DB::commit();

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('success', 'Purchase Requisition successfully deleted.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'An error occurred while deleting data.');
        }
    }
}
