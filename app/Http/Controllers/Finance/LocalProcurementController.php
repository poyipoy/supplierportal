<?php

namespace App\Http\Controllers\Finance;

use App\Exports\LocalGrImportTemplateExport;
use App\Exports\LocalPoImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\RestoreLocalProcurementRequest;
use App\Http\Requests\LocalInvoice\SaveLocalGoodsReceiptRequest;
use App\Http\Requests\LocalInvoice\SaveLocalPurchaseOrderRequest;
use App\Http\Requests\LocalInvoice\UploadGoodsReceiptDocumentRequest;
use App\Http\Requests\LocalInvoice\UploadLocalGrDocumentRequest;
use App\Http\Requests\LocalInvoice\UploadLocalGrImportRequest;
use App\Http\Requests\LocalInvoice\UploadLocalPoDocumentRequest;
use App\Http\Requests\LocalInvoice\UploadLocalPoImportRequest;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalGrDocumentService;
use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use App\Services\LocalInvoice\LocalPoDocumentService;
use App\Services\LocalInvoice\LocalProcurementImportService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class LocalProcurementController extends Controller
{
    private const PO_PRELOAD_LIMIT = 100;

    private const PO_SUGGESTION_LIMIT = 20;

    public function index(Request $request)
    {
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));
        $query = LocalPurchaseOrder::with(['supplier.supplier', 'latestPoAttachment'])->withCount(['goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)]);
        if ($request->filled('q')) {
            $search = '%'.addcslashes((string) $request->string('q'), '%_').'%';
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', $search)
                    ->orWhere('pr_number', 'like', $search);
            });
        }
        if ($supplier) {
            $query->where('supplier_id', $supplier->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('po_date', '>=', $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('po_date', '<=', $request->string('date_to'));
        }

        $metrics = [
            'total_pos' => LocalPurchaseOrder::count(),
            'total_po_amount' => (float) LocalPurchaseOrder::sum('total_amount'),
            'open_pos_count' => LocalPurchaseOrder::where('status', LocalPurchaseOrder::STATUS_OPEN)->count(),
            'active_gr_count' => LocalGoodsReceipt::where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)->count(),
        ];

        return view('finance.local-procurement.index', [
            'purchaseOrders' => $query->latest('po_date')->paginate(25)->withQueryString(),
            'suppliers' => $this->suppliers(),
            'grPoOptions' => $this->grUploadPoOptions(),
            'routePrefix' => $this->prefix(),
            'metrics' => $metrics,
        ]);
    }

    public function create()
    {
        return view('finance.local-procurement.form', [
            'purchaseOrder' => new LocalPurchaseOrder,
            'suppliers' => $this->suppliers(),
            'routePrefix' => $this->prefix(),
            'prOptions' => $this->prOptions(),
        ]);
    }

    public function prSuggestions(Request $request)
    {
        $this->authorize('manage', LocalPurchaseOrder::class);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) $request->input('q', ''));
        $numbers = mb_strlen($term) < 2 ? collect() : LocalPurchaseOrder::query()
            ->whereNotNull('pr_number')->where('pr_number', 'like', '%'.addcslashes($term, '\\%_').'%')
            ->selectRaw('MIN(pr_number) as pr_number')->groupByRaw('BINARY LOWER(TRIM(pr_number))')
            ->orderBy('pr_number')->limit(20)->get()->map(fn ($po) => ['pr_number' => $po->pr_number]);

        return response()->json(['data' => $numbers])->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveLocalPurchaseOrderRequest $request, LocalProcurementMasterService $service)
    {
        $po = $service->createPurchaseOrder($request->user(), $request->validated());

        return redirect()->route($this->prefix().'.show', $po)->with('success', __('local_procurement.feedback.po_created'));
    }

    public function show(LocalPurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);
        $purchaseOrder->load('supplier.supplier');
        $purchaseOrder->loadSum(['invoices as active_invoice_amount' => fn ($q) => $q->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])], 'invoice_amount');
        $purchaseOrder->loadCount(['goodsReceipts', 'goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)]);
        $purchaseOrder->setRelation('goodsReceipts', $purchaseOrder->goodsReceipts()->with(['currentInvoice', 'latestGrAttachment'])->orderByDesc('gr_date')->orderByDesc('id')->limit(25)->get());

        return view('finance.local-procurement.show', ['purchaseOrder' => $purchaseOrder, 'routePrefix' => $this->prefix()]);
    }

    public function edit(LocalPurchaseOrder $purchaseOrder)
    {
        return view('finance.local-procurement.form', compact('purchaseOrder') + [
            'suppliers' => $this->suppliers(),
            'routePrefix' => $this->prefix(),
            'prOptions' => $this->prOptions(),
        ]);
    }

    public function update(SaveLocalPurchaseOrderRequest $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->updatePurchaseOrder($request->user(), $purchaseOrder, $request->validated());

        return redirect()->route($this->prefix().'.show', $purchaseOrder)->with('success', __('local_procurement.feedback.po_updated'));
    }

    public function close(Request $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->closePurchaseOrder($request->user(), $purchaseOrder);

        return back()->with('success', __('local_procurement.feedback.po_closed'));
    }

    public function cancel(Request $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->cancelPurchaseOrder($request->user(), $purchaseOrder);

        return back()->with('success', __('local_procurement.feedback.po_cancelled'));
    }

    public function storeGoodsReceipt(SaveLocalGoodsReceiptRequest $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service, LocalGrDocumentService $documents)
    {
        $data = Arr::except($request->validated(), ['document']);
        if ($request->hasFile('document')) {
            $documents->createGoodsReceiptWithDocument($request->user(), $purchaseOrder, $data, $request->file('document'), $service);
        } else {
            $service->createGoodsReceipt($request->user(), $purchaseOrder, $data);
        }

        return back()->with('success', __('local_procurement.feedback.gr_added'));
    }

    public function uploadGr(UploadLocalGrDocumentRequest $request, LocalGrDocumentService $service)
    {
        $purchaseOrder = $request->purchaseOrder();
        $file = $request->file('file');

        if (strtolower($file->getClientOriginalExtension()) === 'zip') {
            $result = $service->uploadZip($request->user(), $purchaseOrder, $file);
            $message = __('local_procurement.gr_documents.zip_uploaded', ['count' => $result['count'], 'po' => $purchaseOrder->po_number]);
        } else {
            $gr = $service->uploadSinglePdf($request->user(), $purchaseOrder, $file);
            $message = __('local_procurement.gr_documents.uploaded', ['number' => $gr->gr_number]);
        }

        return $this->documentUploaded($request, $message, route($this->prefix().'.show', $purchaseOrder));
    }

    public function poSuggestions(Request $request)
    {
        $this->authorize('manage', LocalPurchaseOrder::class);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) $request->input('q', ''));
        $options = [];
        if (mb_strlen($term) >= 2) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $options = $this->purchaseOrderOptionQuery()
                ->where(fn ($query) => $query->where('po_number', 'like', $like)
                    ->orWhere('pr_number', 'like', $like)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like)
                        ->orWhereHas('supplier', fn ($profile) => $profile->where('company_name', 'like', $like))))
                ->limit(self::PO_SUGGESTION_LIMIT)->get()
                ->map(fn (LocalPurchaseOrder $po) => $this->purchaseOrderOption($po))->all();
        }

        return response()->json(['data' => $options])->header('Cache-Control', 'private, no-store');
    }

    private function grUploadPoOptions(): array
    {
        return $this->purchaseOrderOptionQuery()->limit(self::PO_PRELOAD_LIMIT)->get()
            ->map(fn (LocalPurchaseOrder $po) => $this->purchaseOrderOption($po))->all();
    }

    private function purchaseOrderOptionQuery()
    {
        return LocalPurchaseOrder::query()->with('supplier.supplier')
            ->where('status', '!=', LocalPurchaseOrder::STATUS_CANCELLED)
            ->whereHas('supplier', fn ($query) => $query->localEligible())
            ->orderByDesc('po_date')->orderByDesc('id');
    }

    private function purchaseOrderOption(LocalPurchaseOrder $po): array
    {
        $keywords = array_filter([
            $po->po_number,
            $po->pr_number,
            $po->supplier?->name,
            $po->supplier?->supplier?->company_name,
            $po->status,
        ]);

        return [
            'value' => $po->hash,
            'label' => $po->po_number,
            'sublabel' => $po->supplier?->supplier?->company_name ?: $po->supplier?->name,
            'badge' => $po->status,
            'badgeTone' => $po->status === LocalPurchaseOrder::STATUS_OPEN ? 'success' : 'neutral',
            'searchKeywords' => strtolower(implode(' ', $keywords)),
        ];
    }

    public function uploadGrDocument(UploadGoodsReceiptDocumentRequest $request, LocalGoodsReceipt $goodsReceipt, LocalGrDocumentService $service)
    {
        $gr = $service->attachPdf($request->user(), $goodsReceipt, $request->file('file'));

        return $this->documentUploaded($request, __('local_procurement.gr_documents.uploaded', ['number' => $gr->gr_number]),
            route($this->prefix().'.show', $gr->purchaseOrder));
    }

    public function restore(RestoreLocalProcurementRequest $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        try {
            $service->restorePurchaseOrder($request->user(), $purchaseOrder, $request->validated());
        } catch (ValidationException $exception) {
            $exception->errorBag('restorePo');
            throw $exception;
        }

        return redirect()->route($this->prefix().'.show', $purchaseOrder)->with('success', __('local_procurement.recovery.po_restored'));
    }

    public function restoreGoodsReceipt(RestoreLocalProcurementRequest $request, LocalGoodsReceipt $goodsReceipt, LocalProcurementMasterService $service)
    {
        try {
            $gr = $service->restoreGoodsReceipt($request->user(), $goodsReceipt, $request->validated());
        } catch (ValidationException $exception) {
            $exception->errorBag('restoreGr');
            throw $exception;
        }

        return redirect()->route($this->prefix().'.show', $gr->purchaseOrder)->with('success', __('local_procurement.recovery.gr_restored'));
    }

    public function updateGoodsReceipt(SaveLocalGoodsReceiptRequest $request, LocalGoodsReceipt $goodsReceipt, LocalProcurementMasterService $service)
    {
        $service->updateGoodsReceipt($request->user(), $goodsReceipt, $request->validated());

        return back()->with('success', __('local_procurement.feedback.gr_updated'));
    }

    public function cancelGoodsReceipt(Request $request, LocalGoodsReceipt $goodsReceipt, LocalProcurementMasterService $service)
    {
        $service->cancelGoodsReceipt($request->user(), $goodsReceipt);

        return back()->with('success', __('local_procurement.feedback.gr_cancelled'));
    }

    public function poTemplate()
    {
        return Excel::download(new LocalPoImportTemplateExport, 'infor-erp-po-template.xlsx');
    }

    public function grTemplate()
    {
        return Excel::download(new LocalGrImportTemplateExport, 'infor-erp-gr-template.xlsx');
    }

    public function poPreview(UploadLocalPoImportRequest $request, LocalProcurementImportService $service)
    {
        return $this->queuedPreview($request, $service, 'PO');
    }

    public function grPreview(UploadLocalGrImportRequest $request, LocalProcurementImportService $service)
    {
        return $this->queuedPreview($request, $service, 'GR');
    }

    public function poConfirm(Request $request, LocalProcurementImportService $service)
    {
        return $this->queuedConfirm($request, $service, 'PO');
    }

    public function grConfirm(Request $request, LocalProcurementImportService $service)
    {
        return $this->queuedConfirm($request, $service, 'GR');
    }

    private function queuedPreview(Request $request, LocalProcurementImportService $service, string $kind)
    {
        [$import, $token] = $service->start($request->user(), $request->file('import_file'), $kind);

        return response()->json(['id' => $import->hash, 'status' => $import->status, 'token' => $token,
            'status_url' => route($this->prefix().'.imports.status', $import)], 202);
    }

    private function queuedConfirm(Request $request, LocalProcurementImportService $service, string $kind)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $import = $service->confirm($request->user(), $data['token'], $kind);

        return response()->json(['id' => $import->hash, 'status' => $import->status,
            'status_url' => route($this->prefix().'.imports.status', $import)], $import->status === 'COMPLETED' ? 200 : 202);
    }

    public function uploadPo(UploadLocalPoDocumentRequest $request, LocalPoDocumentService $service)
    {
        $supplier = User::findOrFail($request->validated('supplier_id'));
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'zip') {
            if (config('po_documents.async_enabled')) {
                $batch = app(LocalPoDocumentBatchService::class)->start(
                    $request->user(), $supplier, $file, $request->validated('request_key') ?? (string) Str::uuid()
                );

                return response()->json(app(LocalPoDocumentBatchController::class)->payload($request, $batch), 202);
            }
            $result = $service->uploadZip($request->user(), $supplier, $file, $request->validated('request_key'));
            $message = __('local_procurement.feedback.zip_uploaded', ['count' => $result['count'], 'supplier' => $supplier->name]);
        } else {
            $po = $service->uploadSinglePdf($request->user(), $supplier, $file);
            $message = __('local_procurement.feedback.po_uploaded', ['number' => $po->po_number]);
        }

        $request->session()->flash('success', $message);
        if ($request->expectsJson()) {
            return response()->json(['status' => 'COMPLETED', 'redirect' => route($this->prefix().'.index')]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function suppliers()
    {
        return User::localEligible()->with('supplier')->orderBy('name')->get();
    }

    private function documentUploaded(Request $request, string $message, string $redirect)
    {
        $request->session()->flash('success', $message);

        return $request->expectsJson()
            ? response()->json(['status' => 'COMPLETED', 'redirect' => $redirect])
            : redirect($redirect);
    }

    private function resolveSupplierFilter(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }
        abort_unless(is_string($value) && ! ctype_digit($value), 404);
        $supplier = (new User)->resolveRouteBinding($value);
        abort_unless($supplier instanceof User && $supplier->isLocalEligible(), 404);

        return $supplier;
    }

    private function prOptions(): array
    {
        return LocalPurchaseOrder::query()
            ->whereNotNull('pr_number')
            ->where('pr_number', '!=', '')
            ->selectRaw('MIN(pr_number) as pr_number')
            ->groupByRaw('BINARY LOWER(TRIM(pr_number))')
            ->orderBy('pr_number')
            ->limit(50)
            ->get()
            ->map(fn ($po) => [
                'value' => $po->pr_number,
                'label' => $po->pr_number,
                'searchKeywords' => strtolower($po->pr_number),
            ])
            ->all();
    }

    private function prefix(): string
    {
        return request()->routeIs('purchasing.*') ? 'purchasing.local-procurement' : 'finance.local-procurement';
    }
}
