<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\VendorMaster\VendorChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VendorProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $supplier = $user->supplier;

        $bankAccounts = $user->supplierBankAccounts()->latest('id')->get();
        $documents = $user->supplierMasterDocuments()->latest('id')->get();
        $changeRequests = SupplierChangeRequest::where('supplier_id', $user->id)->latest('id')->get();

        return view('local-supplier.vendor-profile.show', compact(
            'user',
            'supplier',
            'bankAccounts',
            'documents',
            'changeRequests'
        ));
    }

    public function storeChangeRequest(Request $request, VendorChangeRequestService $service)
    {
        $validated = $request->validate([
            'company_name' => 'nullable|string|max:150',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'npwp' => 'nullable|string|max:30',
            'vendor_category' => 'nullable|string|max:50',
            'is_pkp' => 'nullable|boolean',
            'pic_name' => 'nullable|string|max:100',
            'pic_email' => 'nullable|email|max:100',
            'pic_phone' => 'nullable|string|max:30',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_holder_name' => 'nullable|string|max:150',
        ]);

        $service->submitChangeRequest($request->user(), array_filter($validated, fn ($v) => $v !== null));

        return back()->with('success', __('local_procurement.vendor_ui.change_submitted'));
    }

    public function uploadDocument(Request $request)
    {
        $request->validate([
            'document_type' => ['required', 'string', Rule::in(['NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'OTHER'])],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $user = $request->user();
        $file = $request->file('document');
        $path = 'supplier-documents/'.$user->id.'/'.$file->hashName();

        $stored = app(FileInspectionService::class)->storeUpload($file, 'vendor', $path, 'document');
        try {
            SupplierMasterDocument::create([
                'supplier_id' => $user->id,
                'document_type' => $request->input('document_type'),
                'file_path' => $stored['file_path'],
                'original_filename' => $stored['file_name'],
                'mime_type' => $stored['file_type'],
                'file_size' => $stored['file_size'],
                'file_inspection_id' => $stored['file_inspection_id'],
                'uploaded_by' => $user->id,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($stored['file_path']);
            throw $exception;
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('local_procurement.vendor_ui.document_uploaded'));

            return response()->json(['redirect' => route('local-supplier.vendor-profile.show')]);
        }

        return back()->with('success', __('local_procurement.vendor_ui.document_uploaded'));
    }
}
