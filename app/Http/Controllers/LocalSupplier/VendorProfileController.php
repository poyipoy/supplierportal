<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\VendorMaster\VendorChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            'bank_confirmation_document_id' => ['nullable', 'regex:/\A[A-Za-z0-9]+\z/', 'max:100'],
        ]);

        $hasBankField = ! empty(trim((string) ($validated['bank_name'] ?? '')))
            || ! empty(trim((string) ($validated['account_number'] ?? '')))
            || ! empty(trim((string) ($validated['account_holder_name'] ?? '')))
            || ! empty($validated['bank_confirmation_document_id']);

        if ($hasBankField) {
            $bankErrors = [];
            if (empty(trim((string) ($validated['bank_name'] ?? '')))) {
                $bankErrors['bank_name'] = __('finance_controls.bank_fields_required_together');
            }
            if (empty(trim((string) ($validated['account_number'] ?? '')))) {
                $bankErrors['account_number'] = __('finance_controls.bank_fields_required_together');
            }
            if (empty(trim((string) ($validated['account_holder_name'] ?? '')))) {
                $bankErrors['account_holder_name'] = __('finance_controls.bank_fields_required_together');
            }
            if (empty($validated['bank_confirmation_document_id'])) {
                $bankErrors['bank_confirmation_document_id'] = __('finance_controls.bank_document');
            }

            if (! empty($bankErrors)) {
                throw ValidationException::withMessages($bankErrors);
            }

            $hash = $validated['bank_confirmation_document_id'];
            $document = ! ctype_digit((string) $hash)
                ? (new SupplierMasterDocument)->resolveRouteBinding($hash)
                : SupplierMasterDocument::find((int) $hash);

            if (! $document || (int) $document->supplier_id !== (int) $request->user()->id || $document->document_type !== SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING) {
                throw ValidationException::withMessages(['bank_confirmation_document_id' => __('finance_controls.bank_document')]);
            }

            try {
                app(FileAccessGuard::class)->assertReadable($document);
            } catch (HttpException) {
                throw ValidationException::withMessages(['bank_confirmation_document_id' => __('finance_controls.bank_document')]);
            }

            $validated['bank_confirmation_document_id'] = $document->id;
            $changeType = SupplierChangeRequest::TYPE_BANK_ACCOUNT;
        } else {
            unset($validated['bank_name'], $validated['account_number'], $validated['account_holder_name'], $validated['bank_confirmation_document_id']);
            $changeType = SupplierChangeRequest::TYPE_PROFILE;
        }

        $proposedData = array_filter($validated, fn ($v) => $v !== null && (! is_string($v) || trim($v) !== ''));

        $service->submitChangeRequest($request->user(), $proposedData, $changeType);

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
