<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\LocalInvoice;
use App\Models\SupplierChangeRequest;
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
            ->with(['supplier.supplier', 'requester'])
            ->latest('id')
            ->get();

        return view('purchasing.local-vendors.index', compact('vendors', 'pendingRequests'));
    }

    public function show(User $vendor)
    {
        $vendor->load(['supplier.bankAccounts', 'supplier.masterDocuments', 'supplier.changeRequests']);

        return view('purchasing.local-vendors.show', compact('vendor'));
    }

    public function approveChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $service->approve($request, $req->user(), $req->input('notes'));

        return back()->with('success', 'Vendor change request approved by Purchasing.');
    }

    public function rejectChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $req->validate(['notes' => 'required|string|max:1000']);
        $service->reject($request, $req->user(), $req->input('notes'));

        return back()->with('success', 'Vendor change request rejected by Purchasing.');
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
            'verification' => $invoice->currentVerification,
            'readOnly' => true,
        ]);
    }
}
