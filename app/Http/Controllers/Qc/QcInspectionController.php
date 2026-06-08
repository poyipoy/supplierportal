<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\StatusHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class QcInspectionController extends Controller
{
    private const ACTUAL_FIELD_BY_DIMENSION = [
        'thickness' => 'actual_thickness',
        'd_inner' => 'actual_d_inner',
        'd_outer' => 'actual_d_outer',
        'width' => 'actual_width',
        'length' => 'actual_length',
        'weight' => 'actual_weight',
    ];

    /**
     * Display inspection lists for waiting and history tabs.
     */
    public function index()
    {
        $waitingCount = PurchaseOrder::where('status', 'waiting_qc')->count();
        $historyCount = QcInspection::count();

        return view('qc.inspections.index', compact('waitingCount', 'historyCount'));
    }

    public function dataWaiting(Request $request)
    {
        $query = PurchaseOrder::with([
            'supplier',
            'quotations' => fn($query) => $query->withCount('items'),
        ])
            ->where('status', 'waiting_qc')
            ->orderBy('actual_arrival', 'asc');

        return DataTables::eloquent($query)
            ->addColumn('po_number_display', fn($po) => $po->po_number)
            ->addColumn('supplier_name', fn($po) => $po->supplier->name ?? '-')
            ->addColumn('arrival_date', fn($po) => $po->actual_arrival ? $po->actual_arrival->format('d M Y') : '-')
            ->addColumn('item_count', fn($po) => $po->quotations->sum('items_count') . ' Item')
            ->addColumn('action', fn($po) => '<a href="' . route('qc.inspections.create', $po->id) . '" class="btn btn-sm btn-primary" style="background-color: var(--adasi-blue);"><i class="bi bi-clipboard-check me-1"></i> Start Inspection</a>')
            ->rawColumns(['action'])
            ->make(true);
    }

    public function dataHistory(Request $request)
    {
        $query = QcInspection::with(['purchaseOrder.supplier', 'inspector'])
            ->orderBy('inspected_at', 'desc');

        if ($request->filled('status') && in_array($request->status, ['ok', 'ng'], true)) {
            $query->where('status', $request->status);
        }

        return DataTables::eloquent($query)
            ->addColumn('po_number', fn($i) => $i->purchaseOrder->po_number ?? '-')
            ->addColumn('supplier_name', fn($i) => $i->purchaseOrder->supplier->name ?? '-')
            ->addColumn('inspected_date', fn($i) => $i->inspected_at?->format('d M Y, H:i') ?? '-')
            ->addColumn('status_badge', fn($i) => StatusHelper::badge(
                StatusHelper::qcBadge($i->status),
                StatusHelper::qcLabel($i->status)
            ))
            ->addColumn('inspector_name', fn($i) => $i->inspector->name ?? '-')
            ->addColumn('action', fn($i) => '<a href="' . route('qc.inspections.show', $i->id) . '" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i> Details</a>')
            ->rawColumns(['status_badge', 'action'])
            ->make(true);
    }

    /**
     * Start inspection form.
     */
    public function create($po_id)
    {
        $po = PurchaseOrder::with(['supplier', 'quotations.items.prItem'])->findOrFail($po_id);

        if ($po->status !== 'waiting_qc') {
            return redirect()->route('qc.inspections.index')->with('error', 'This PO is not in Waiting QC status.');
        }

        // Prevent duplicate inspections.
        if (QcInspection::where('po_id', $po->id)->exists()) {
            return redirect()->route('qc.inspections.index')->with('error', 'This PO has already been inspected.');
        }

        return view('qc.inspections.create', compact('po'));
    }

    /**
     * Save inspection results.
     */
    public function store(Request $request, $po_id)
    {
        $po = PurchaseOrder::with(['quotations.items.prItem'])->findOrFail($po_id);
        $poPrItems = $po->quotations
            ->flatMap(fn($quotation) => $quotation->items->pluck('prItem'))
            ->filter()
            ->keyBy('id');

        $this->prepareInspectionInput($request, $poPrItems);

        $rules = [
            'items' => 'required|array',
            'items.*.pr_item_id' => ['required', Rule::in($poPrItems->keys()->all())],
            'items.*.actual_thickness' => 'nullable|numeric',
            'items.*.actual_d_inner' => 'nullable|numeric',
            'items.*.actual_d_outer' => 'nullable|numeric',
            'items.*.actual_width' => 'nullable|numeric',
            'items.*.actual_length' => 'nullable|numeric',
            'items.*.actual_weight' => 'nullable|numeric',
            'items.*.status' => 'required|in:ok,ng',
            'items.*.notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'nullable|array',
            'attachments.*.*' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
        ];

        foreach ($request->input('items', []) as $index => $itemData) {
            if (($itemData['status'] ?? null) === 'ng') {
                $rules["attachments.{$index}"] = 'required|array|min:1';
                $rules["attachments.{$index}.*"] = 'required|file|mimes:jpg,jpeg,png|max:10240';
            }
        }

        $validated = $request->validate($rules, [
            'items.*.pr_item_id.in' => 'The inspected material does not match this PO.',
            'attachments.*.required' => 'Evidence photos are required for every NG item.',
            'attachments.*.min' => 'Evidence photos are required for every NG item.',
            'attachments.*.*.required' => 'Evidence photos are required for every NG item.',
            'attachments.*.*.mimes' => 'NG evidence photos must be JPG, JPEG, or PNG files.',
            'attachments.*.*.max' => 'Each NG evidence photo must not exceed 10MB.',
        ]);

        try {
            DB::beginTransaction();

            $po = PurchaseOrder::whereKey($po_id)
                ->lockForUpdate()
                ->firstOrFail();
            $po->load(['supplier', 'quotations.items.prItem']);

            if ($po->status !== 'waiting_qc') {
                throw new \RuntimeException('This PO is not valid for inspection.');
            }

            if (QcInspection::where('po_id', $po->id)->exists()) {
                throw new \RuntimeException('This PO has already been inspected.');
            }

            // Determine the overall inspection status.
            $overallStatus = 'ok';
            foreach ($validated['items'] as $itemData) {
                if ($itemData['status'] === 'ng') {
                    $overallStatus = 'ng';
                    break;
                }
            }

            // Create Inspection
            $inspection = QcInspection::create([
                'po_id' => $po->id,
                'inspected_by' => auth()->id(),
                'status' => $overallStatus,
                'inspected_at' => now(),
            ]);

            $uploadedFiles = $request->file('attachments', []);

            // Save QC Items
            foreach ($validated['items'] as $index => $itemData) {
                $measurements = $this->sanitizeActualMeasurements(
                    $itemData,
                    $poPrItems->get((int) $itemData['pr_item_id'])
                );

                $qcItem = QcItem::create($measurements + [
                    'inspection_id' => $inspection->id,
                    'pr_item_id' => $itemData['pr_item_id'],
                    'status' => $itemData['status'],
                    'notes' => $itemData['notes'] ?? null,
                ]);

                if (($itemData['status'] ?? null) === 'ng') {
                    foreach ($uploadedFiles[$index] ?? [] as $file) {
                        if (! $file instanceof UploadedFile || ! $file->isValid()) {
                            continue;
                        }

                        $this->saveAttachment($file, $inspection);
                    }
                }
            }

            if ($overallStatus === 'ng' && ! $inspection->attachments()->exists()) {
                throw new \RuntimeException('NG evidence photos were not uploaded. Please upload the evidence photos again before saving the inspection.');
            }

            // Update PO Status & Send Notification
            $purchasingUsers = User::where('role', 'purchasing')->get();

            if ($overallStatus === 'ok') {
                $po->update(['status' => 'completed']);
                foreach ($purchasingUsers as $pUser) {
                    /** @var User $pUser */
                    $pUser->notify(new SystemNotification(
                        'QC Inspection Completed',
                        'Material from ' . $po->po_number . ' has passed QC inspection.',
                        route('purchasing.purchase-orders.show', $po->id),
                        'bi-check-circle text-success'
                    ));
                }
            } else {
                $po->update(['status' => 'claim_needed']);
                foreach ($purchasingUsers as $pUser) {
                    /** @var User $pUser */
                    $pUser->notify(new SystemNotification(
                        'NG Material Found',
                        'Material from ' . $po->po_number . ' was marked NG by QC. Please submit a claim to the supplier.',
                        route('purchasing.claims.create', $inspection->id),
                        'bi-exclamation-triangle text-danger'
                    ));
                }
            }

            DB::commit();

            return redirect()->route('qc.inspections.show', $inspection->id)->with('success', 'Inspection result successfully saved.');

        } catch (\RuntimeException $e) {
            DB::rollBack();
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('QC Inspection store failed', [
                'po_id' => $po_id,
                'user_id' => auth()->id(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'An error occurred while saving the inspection. Please try again.');
        }
    }

    private function prepareInspectionInput(Request $request, $poPrItems): void
    {
        $items = $request->input('items');

        if (! is_array($items)) {
            return;
        }

        $prepared = [];
        foreach ($items as $index => $itemData) {
            if (! is_array($itemData)) {
                continue;
            }

            $prItem = $poPrItems->get((int) ($itemData['pr_item_id'] ?? 0));
            $prepared[$index] = array_merge(
                $itemData,
                $this->sanitizeActualMeasurements($itemData, $prItem)
            );
        }

        $request->merge(['items' => $prepared]);
    }

    private function sanitizeActualMeasurements(array $itemData, ?PrItem $prItem): array
    {
        $relevantDimensions = $prItem
            ? PrItem::relevantDimensionFields($prItem->shape)
            : [];
        $relevantDimensions[] = 'weight';

        $measurements = [];
        foreach (self::ACTUAL_FIELD_BY_DIMENSION as $dimension => $field) {
            $measurements[$field] = in_array($dimension, $relevantDimensions, true)
                ? ($itemData[$field] ?? null)
                : null;
        }

        return $measurements;
    }

    /**
     * Display inspection details.
     */
    public function show($id)
    {
        $inspection = QcInspection::with([
            'purchaseOrder.supplier',
            'inspector',
            'items.prItem',
            'attachments'
        ])->findOrFail($id);

        return view('qc.inspections.show', compact('inspection'));
    }

    /**
     * Add evidence photos for a saved NG inspection.
     */
    public function storeAttachments(Request $request, $id)
    {
        $inspection = QcInspection::findOrFail($id);

        if ($inspection->status !== 'ng') {
            return back()->with('error', 'Evidence photos can only be added for NG inspections.');
        }

        $request->validate([
            'attachments' => 'required|array|min:1',
            'attachments.*' => 'required|file|mimes:jpg,jpeg,png|max:10240',
        ], [
            'attachments.required' => 'Select at least 1 NG evidence photo.',
            'attachments.min' => 'Select at least 1 NG evidence photo.',
            'attachments.*.mimes' => 'NG evidence photos must be JPG, JPEG, or PNG files.',
            'attachments.*.max' => 'Each NG evidence photo must not exceed 10MB.',
        ]);

        foreach ($request->file('attachments', []) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $this->saveAttachment($file, $inspection);
        }

        return back()->with('success', 'QC evidence photos successfully added.');
    }

    private function saveAttachment(UploadedFile $file, Model $attachable): void
    {
        $path = 'attachments/' . now()->format('Y/m') . '/' . $file->hashName();
        $stream = fopen($file->getPathname(), 'r');

        if (! $stream) {
            throw new \RuntimeException('File cannot be read. Please upload the file again.');
        }

        try {
            Storage::disk('private')->put($path, $stream);
        } finally {
            fclose($stream);
        }

        $attachable->attachments()->create([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getMimeType(),
            'uploaded_by' => auth()->id(),
        ]);
    }
}
