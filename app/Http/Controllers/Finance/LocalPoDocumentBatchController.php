<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\LocalPoDocumentBatch;
use App\Services\LocalInvoice\LocalPoDocumentBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocalPoDocumentBatchController extends Controller
{
    public function status(Request $request, LocalPoDocumentBatch $poDocumentBatch)
    {
        Gate::authorize('view', $poDocumentBatch);

        return response()->json($this->payload($request, $poDocumentBatch));
    }

    public function retry(Request $request, LocalPoDocumentBatch $poDocumentBatch, LocalPoDocumentBatchService $service)
    {
        return response()->json($this->payload($request, $service->retry($request->user(), $poDocumentBatch)), 202);
    }

    public function payload(Request $request, LocalPoDocumentBatch $batch): array
    {
        $prefix = $request->routeIs('purchasing.*') ? 'purchasing.local-procurement' : 'finance.local-procurement';

        return ['id' => $batch->hash, 'status' => $batch->status, 'processed' => $batch->processed_count, 'total' => $batch->pdf_count,
            'message' => in_array($batch->status, ['REJECTED', 'FAILED'], true) && $batch->failure_code === 'storage_limit'
                ? __('po_documents.storage_limit') : __('po_documents.states.'.$batch->status),
            'status_url' => route($prefix.'.po-documents.status', $batch),
            'retry_url' => $batch->status === 'FAILED' && config('po_documents.async_enabled') ? route($prefix.'.po-documents.retry', $batch) : null];
    }
}
