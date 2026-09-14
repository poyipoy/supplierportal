<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Services\VendorMaster\VendorChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

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

        return back()->with('success', 'Perubahan profil vendor telah diajukan dan menunggu verifikasi.');
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

        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            throw new RuntimeException('Unable to read uploaded document.');
        }

        try {
            if (! Storage::disk('private')->put($path, $stream)) {
                throw new RuntimeException('Unable to store document to private storage.');
            }
        } finally {
            fclose($stream);
        }

        SupplierMasterDocument::create([
            'supplier_id' => $user->id,
            'document_type' => $request->input('document_type'),
            'file_path' => $path,
            'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);

        return back()->with('success', 'Dokumen legalitas berhasil diunggah.');
    }
}
