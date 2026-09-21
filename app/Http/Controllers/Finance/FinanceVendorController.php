<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\SupplierChangeRequest;
use App\Models\User;
use App\Services\VendorMaster\VendorChangeRequestService;
use App\Services\VendorMaster\VendorMasterService;
use Illuminate\Http\Request;

class FinanceVendorController extends Controller
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

        return view('finance.vendors.index', compact('vendors', 'pendingRequests'));
    }

    public function show(User $vendor)
    {
        $vendor->load(['supplier.bankAccounts', 'supplier.masterDocuments', 'supplier.changeRequests']);

        return view('finance.vendors.show', compact('vendor'));
    }

    public function approveChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $service->approve($request, $req->user(), $req->input('notes'));

        return back()->with('success', 'Vendor change request approved and applied to master.');
    }

    public function rejectChange(SupplierChangeRequest $request, Request $req, VendorChangeRequestService $service)
    {
        $req->validate(['notes' => 'required|string|max:1000']);
        $service->reject($request, $req->user(), $req->input('notes'));

        return back()->with('success', 'Vendor change request rejected.');
    }
}
