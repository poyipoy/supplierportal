<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierContact\StoreSupplierContactRequest;
use App\Models\SupplierChangeRequest;
use App\Services\VendorMaster\VendorChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplierCompanyProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $contactRequests = SupplierChangeRequest::where('supplier_id', $user->id)
            ->contact()
            ->with('reviewer:id,name')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('supplier.company-profile.show', [
            'user' => $user,
            'profile' => $user->supplier,
            'contactRequests' => $contactRequests,
            'pendingRequest' => $contactRequests->first(fn (SupplierChangeRequest $item) => $item->isPending()),
        ]);
    }

    public function storeContactRequest(StoreSupplierContactRequest $request, VendorChangeRequestService $service)
    {
        try {
            $service->submitContactRequest($request->user(), $request->validated());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['pic_name' => $exception->getMessage()]);
        }

        $request->session()->flash('success', __('supplier_contact.profile.submitted'));
        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('supplier.company-profile.show')]);
        }

        return redirect()->route('supplier.company-profile.show');
    }
}
