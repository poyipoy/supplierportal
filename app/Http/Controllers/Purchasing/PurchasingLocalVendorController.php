<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\LocalInvoice;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Models\User;
use App\Services\VendorMaster\VendorChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchasingLocalVendorController extends Controller
{
    public function index()
    {
        $vendors = User::where('role', 'supplier')
            ->whereHas('supplierScopes', fn ($q) => $q->where('scope', 'local'))
            ->with(['supplier.bankAccounts', 'supplier.activeBankAccount'])
            ->latest('id')
            ->paginate(20);

        $pendingRequests = SupplierChangeRequest::where('status', SupplierChangeRequest::STATUS_PENDING)
            ->excludingContact()
            ->with(['supplier.supplier', 'requester'])
            ->latest('id')
            ->get();
        $documentIds = $pendingRequests->pluck('proposed_data.bank_confirmation_document_id')->filter()->unique()->all();
        $pendingRequests->load(['supplier.supplier.masterDocuments' => fn ($query) => $query
            ->whereKey($documentIds)->where('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
            ->select(['id', 'supplier_id', 'original_filename'])]);

        return view('purchasing.local-vendors.index', compact('vendors', 'pendingRequests'));
    }

    public function show(User $vendor)
    {
        abort_unless($vendor->isLocalEligible(), 404);

        $vendor->load(['supplier.bankAccounts', 'supplier.masterDocuments', 'supplier.changeRequests']);

        return view('purchasing.local-vendors.show', compact('vendor'));
    }

    public function approveChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $service->approve($request, $req->user(), $req->input('notes'));

        return back()->with('success', __('local_procurement.vendor_ui.purchasing_approved'));
    }

    public function rejectChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $req->validate(['notes' => 'required|string|max:1000']);
        $service->reject($request, $req->user(), $req->input('notes'));

        return back()->with('success', __('local_procurement.vendor_ui.purchasing_rejected'));
    }

    /**
     * Purchasing Read-Only Verification View.
     */
    public function showInvoice(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'supplier.supplier',
            'receipt',
            'revisions.documents',
            'statusHistories.actor',
            'physicalVerifications.actor',
            'currentVerification.verifier',
        ]);

        return view('purchasing.local-vendors.invoice-show', [
            'invoice' => $invoice,
            'verification' => $invoice->currentVerification?->revision_number === $invoice->revision_number ? $invoice->currentVerification : null,
            'readOnly' => true,
        ]);
    }
}
