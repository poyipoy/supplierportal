<?php

namespace App\Http\Controllers\Finance;

use App\Exports\LocalPoGrImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\SaveLocalGoodsReceiptRequest;
use App\Http\Requests\LocalInvoice\SaveLocalPurchaseOrderRequest;
use App\Http\Requests\LocalInvoice\UploadLocalPoDocumentRequest;
use App\Imports\LocalPoGrImport;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoDocumentService;
use App\Services\LocalInvoice\LocalPoGrImportService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Support\SpreadsheetImportReader;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Maatwebsite\Excel\Facades\Excel;

class LocalProcurementController extends Controller
{
    public function index(Request $request)
    {
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));
        $query = LocalPurchaseOrder::with('supplier.supplier')->withCount(['goodsReceipts as active_gr_count' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)])
            ->withSum(['goodsReceipts as active_gr_amount' => fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)], 'received_amount');
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
            'active_gr_amount' => (float) LocalGoodsReceipt::where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)->sum('received_amount'),
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

        return redirect()->route($this->prefix().'.show', $po)->with('success', 'Local PO created.');
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

        return redirect()->route($this->prefix().'.show', $purchaseOrder)->with('success', 'Local PO updated.');
    }

    public function close(Request $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->closePurchaseOrder($request->user(), $purchaseOrder);

        return back()->with('success', 'Local PO closed.');
    }

    public function cancel(Request $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->cancelPurchaseOrder($request->user(), $purchaseOrder);

        return back()->with('success', 'Local PO cancelled.');
    }

    public function storeGoodsReceipt(SaveLocalGoodsReceiptRequest $request, LocalPurchaseOrder $purchaseOrder, LocalProcurementMasterService $service)
    {
        $service->createGoodsReceipt($request->user(), $purchaseOrder, $request->validated());

        return back()->with('success', 'Goods Receipt added.');
    }

    public function updateGoodsReceipt(SaveLocalGoodsReceiptRequest $request, LocalGoodsReceipt $goodsReceipt, LocalProcurementMasterService $service)
    {
        $service->updateGoodsReceipt($request->user(), $goodsReceipt, $request->validated());

        return back()->with('success', 'Goods Receipt updated.');
    }

    public function cancelGoodsReceipt(Request $request, LocalGoodsReceipt $goodsReceipt, LocalProcurementMasterService $service)
    {
        $service->cancelGoodsReceipt($request->user(), $goodsReceipt);

        return back()->with('success', 'Goods Receipt cancelled.');
    }

    public function template()
    {
        return Excel::download(new LocalPoGrImportTemplateExport, 'local-po-gr-template.xlsx');
    }

    public function preview(Request $request, LocalPoGrImportService $service)
    {
        $request->validate(['import_file' => ['required', File::types(['xlsx'])->max(10 * 1024)]]);
        $import = new LocalPoGrImport;
        SpreadsheetImportReader::import($import, $request->file('import_file'));
        $base = $import->preview();
        $result = $base['success'] ? $service->validate($base['rows']) : $base;
        $service->recordPreview($request->user(), $request->file('import_file')->getClientOriginalName(), $result);
        $token = Str::random(40);
        if ($result['success']) {
            session()->put('local_po_gr_import.'.$token, $base['rows']);
            session()->put('local_po_gr_import_meta.'.$token, [
                'original_filename' => $request->file('import_file')->getClientOriginalName(),
                'previewed_at' => now()->toIso8601String(),
            ]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'preview' => $result,
                'token' => $token,
                'confirm_url' => route($this->prefix().'.import.confirm'),
            ]);
        }

        return redirect()->route($this->prefix().'.index');
    }

    public function confirm(Request $request, LocalPoGrImportService $service)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $rows = session()->pull('local_po_gr_import.'.$data['token']);
        $metadata = session()->pull('local_po_gr_import_meta.'.$data['token'], []);
        abort_unless(is_array($rows), 419, 'Import preview expired. Upload the workbook again.');
        $counts = $service->import($request->user(), $rows, is_array($metadata) ? $metadata : []);

        return redirect()->route($this->prefix().'.index')->with('success', "Import completed: {$counts['newPo']} PO and {$counts['newGr']} GR created.");
    }

    public function uploadPo(UploadLocalPoDocumentRequest $request, LocalPoDocumentService $service)
    {
        $supplier = User::findOrFail($request->validated('supplier_id'));
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'zip') {
            $result = $service->uploadZip($request->user(), $supplier, $file);
            $message = "Berhasil mengunggah {$result['count']} dokumen PO dari arsip ZIP untuk supplier {$supplier->name}.";
        } else {
            $po = $service->uploadSinglePdf($request->user(), $supplier, $file);
            $message = "Dokumen PO berhasil diunggah dan ditautkan ke PO {$po->po_number}.";
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
