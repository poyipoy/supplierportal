<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PoDocument;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PoDocumentController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Update status document via AJAX.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'status' => ['required', 'string', Rule::in(PoDocument::STATUSES)],
        ]);

        $doc = PoDocument::with('purchaseOrder.creator')->findOrFail($id);
        $completedStatuses = ['received', 'verified', 'done'];
        $wasAllDone = $doc->purchaseOrder
            ->documents()
            ->whereIn('status', $completedStatuses)
            ->count() === 4;
        $statusChanged = $doc->status !== $request->status;

        $doc->update(['status' => $request->status]);
        $doc->refresh();

        $po = $doc->purchaseOrder()->with('creator')->first();
        if ($statusChanged && $po?->creator) {
            $this->notifications->send(
                $po->creator,
                'document.status_updated',
                'document.status_updated:'.$doc->id.':'.$doc->status.':'.($doc->updated_at?->format('YmdHis.u') ?? 'updated'),
                'documents.copy.document_status_updated',
                'documents.notify.updated_body',
                route('purchasing.purchase-orders.show', $po, absolute: false),
                'file-check text-primary',
                [
                    'category' => NotificationCategory::DOCUMENT,
                    'document_id' => $doc->id,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                ],
                ['po' => $po->po_number],
                ['document' => 'documents.notify.type_'.(in_array($doc->doc_type, ['invoice', 'bl', 'packing_list', 'form_e'], true) ? $doc->doc_type : 'other'), 'status' => 'documents.notify.status_'.(in_array($doc->status, ['pending', 'received', 'verified', 'issued', 'processing', 'done'], true) ? $doc->status : 'other')],
            );
        }

        $allDone = $po
            ? $po->documents()->whereIn('status', $completedStatuses)->count() === 4
            : false;

        if ($allDone && ! $wasAllDone && $po?->creator) {
            $this->notifications->send(
                $po->creator,
                'document.all_completed',
                "document.all_completed:{$po->id}",
                'documents.copy.all_import_documents_complete',
                'documents.notify.completed_body',
                route('purchasing.purchase-orders.show', $po, absolute: false),
                'circle-check text-success',
                [
                    'category' => NotificationCategory::DOCUMENT,
                    'document_id' => $doc->id,
                    'po_id' => $po->id,
                    'po_number' => $po->po_number,
                ],
                ['po' => $po->po_number],
            );
        }

        return response()->json([
            'success' => true,
            'message' => __('documents.copy.status_document_successfully_updated'),
            'all_docs_complete' => $allDone,
            'doc' => [
                'id' => $doc->id,
                'doc_type' => $doc->doc_type,
                'status' => $doc->status,
                'updated_at' => \App\Support\BusinessTime::format($doc->updated_at, 'd M Y, H:i'),
            ],
        ]);
    }
}
