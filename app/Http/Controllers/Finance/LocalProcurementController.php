<?php

namespace App\Http\Controllers\Finance;

use App\Exports\LocalGrImportTemplateExport;
use App\Exports\LocalPoImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\SaveLocalGoodsReceiptRequest;
use App\Http\Requests\LocalInvoice\SaveLocalPurchaseOrderRequest;
use App\Http\Requests\LocalInvoice\UploadLocalGrImportRequest;
use App\Http\Requests\LocalInvoice\UploadLocalPoDocumentRequest;
use App\Http\Requests\LocalInvoice\UploadLocalPoImportRequest;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use App\Services\LocalInvoice\LocalPoDocumentService;
use App\Services\LocalInvoice\LocalProcurementImportService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class LocalProcurementController extends Controller
{
    public function index(Request $request)
    {
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));
        $query = LocalPurchaseOrder::with('supplier.supplier')->withCount(['goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)]);
        if ($request->filled('q')) {
            $query->where('po_number', 'like', '%'.addcslashes($request->string('q'), '%_').'%');
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
            'routePrefix' => $this->prefix(),
            'metrics' => $metrics,
        ]);
    }

    public function create()
    {
        return view('finance.local-procurement.form', ['purchaseOrder' => new LocalPurchaseOrder, 'suppliers' => $this->suppliers(), 'routePrefix' => $this->prefix()]);
    }

    public function store(SaveLocalPurchaseOrderRequest $request, LocalProcurementMasterService $service)
    {
        $po = $service->createPurchaseOrder($request->user(), $request->validated());

        return redirect()->route($this->prefix().'.show', $po)->with('success', __('local_procurement.feedback.po_created'));
    }

    public function show(LocalPurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier.supplier', 'goodsReceipts.currentInvoice', 'invoices']);

        return view('finance.local-procurement.show', ['purchaseOrder' => $purchaseOrder, 'routePrefix' => $this->prefix()]);
    }

    public function edit(LocalPurchaseOrder $purchaseOrder)
    {
        return view('finance.local-procurement.form', compact('purchaseOrder') + ['suppliers' => $this->suppliers(), 'routePrefix' => $this->prefix()]);
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

    public function storeGoodsReceipt(SaveLocalGoodsReceiptRequest $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->createGoodsReceipt($request->user(), $purchaseOrder, $request->validated());

        return back()->with('success', __('local_procurement.feedback.gr_added'));
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

    private function prefix(): string
    {
        return request()->routeIs('purchasing.*') ? 'purchasing.local-procurement' : 'finance.local-procurement';
    }
}
