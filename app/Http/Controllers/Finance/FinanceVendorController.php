<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorMaster\UpdateSupplierPaymentTermRequest;
use App\Models\LocalInvoice;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Models\User;
use App\Services\VendorMaster\VendorChangeRequestService;
use App\Services\VendorMaster\VendorMasterService;
use Illuminate\Http\Request;

class FinanceVendorController extends Controller
{
    public function index()
    {
        $vendors = User::localEligible()
            ->with(['supplier.bankAccounts', 'supplier.activeBankAccount'])
            ->latest('id')
            ->paginate(20);

        $pendingRequests = SupplierChangeRequest::where('status', SupplierChangeRequest::STATUS_PENDING)
            ->whereHas('supplier', fn ($q) => $q->localEligible())
            ->with(['supplier.supplier', 'requester'])
            ->latest('id')
            ->get();
        $documentIds = $pendingRequests->pluck('proposed_data.bank_confirmation_document_id')->filter()->unique()->all();
        $pendingRequests->load(['supplier.supplier.masterDocuments' => fn ($query) => $query
            ->whereKey($documentIds)->where('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
            ->select(['id', 'supplier_id', 'original_filename'])]);

        return view('finance.vendors.index', compact('vendors', 'pendingRequests'));
    }

    public function show(User $vendor)
    {
        abort_unless($vendor->isLocalEligible(), 404);

        $vendor->load(['supplier.bankAccounts', 'supplier.masterDocuments', 'supplier.changeRequests']);

        return view('finance.vendors.show', compact('vendor'));
    }

    public function editPaymentTerm(User $vendor)
    {
        abort_unless($vendor->isLocalEligible() && $vendor->supplier, 404);
        $this->authorize('updatePaymentTerm', $vendor->supplier);

        // Invoice yang belum diterima kasir akan memakai term baru saat diterima.
        $affectedInvoices = LocalInvoice::where('supplier_id', $vendor->id)
            ->whereIn('status', [LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, LocalInvoice::STATUS_NEED_REVISION])
            ->count();

        return view('finance.vendors.payment-term', [
            'vendor' => $vendor,
            'supplier' => $vendor->supplier,
            'affectedInvoices' => $affectedInvoices,
        ]);
    }

    public function updatePaymentTerm(UpdateSupplierPaymentTermRequest $request, User $vendor, VendorMasterService $service)
    {
        $data = $request->validated();
        $service->updatePaymentTerm($vendor, (int) $data['payment_term_days'], $data['reason'], $request->user());

        return redirect()->route('finance.vendor-master.show', $vendor)
            ->with('success', __('local_procurement.payment_term.updated', ['days' => (int) $data['payment_term_days']]));
    }

    public function approveChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $service->approve($request, $req->user(), $req->input('notes'));

        return back()->with('success', __('finance.feedback.vendor_approved'));
    }

    public function rejectChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $req->validate(['notes' => 'required|string|max:1000']);
        $service->reject($request, $req->user(), $req->input('notes'));

        return back()->with('success', __('finance.feedback.vendor_rejected'));
    }
}
