<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PoDocument;
use App\Notifications\SystemNotification;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;

class PoDocumentController extends Controller
{
    /**
     * Update status document via AJAX.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string',
        ]);

        $doc = PoDocument::with('purchaseOrder.creator')->findOrFail($id);
        $completedStatuses = ['received', 'verified', 'done'];
        $wasAllDone = $doc->purchaseOrder
            ->documents()
            ->whereIn('status', $completedStatuses)
            ->count() === 4;

        $doc->update(['status' => $request->status]);
        $doc->refresh();

        $po = $doc->purchaseOrder()->with('creator')->first();
        $docLabel = [
            'invoice' => 'Invoice',
            'bl' => 'Bill of Lading',
            'packing_list' => 'Packing List',
            'form_e' => 'Form-E',
        ][$doc->doc_type] ?? $doc->doc_type;
        $statusLabel = [
            'pending' => 'Not Available',
            'received' => 'Accepted',
            'verified' => 'Verified',
            'issued' => 'Issued',
            'processing' => 'Processing',
            'done' => 'Completed',
        ][$doc->status] ?? $doc->status;

        if ($po?->creator) {
            $po->creator->notify(new SystemNotification(
                'Document Status Updated',
                "Document {$docLabel} on PO {$po->po_number} has been updated to \"{$statusLabel}\".",
                route('purchasing.purchase-orders.show', $po),
                'bi-file-earmark-check text-primary',
                ['category' => NotificationCategory::DOCUMENT]
            ));
        }

        $allDone = $po
            ? $po->documents()->whereIn('status', $completedStatuses)->count() === 4
            : false;

        if ($allDone && !$wasAllDone && $po?->creator) {
            $po->creator->notify(new SystemNotification(
                'All Import Documents Complete',
                "All import documents for PO {$po->po_number} are complete. Confirm material arrival if it has arrived.",
                route('purchasing.purchase-orders.show', $po),
                'bi-check2-circle text-success',
                ['category' => NotificationCategory::DOCUMENT]
            ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Status document successfully updated.',
            'all_docs_complete' => $allDone,
            'doc' => [
                'id' => $doc->id,
                'doc_type' => $doc->doc_type,
                'status' => $doc->status,
                'updated_at' => $doc->updated_at->format('d M Y, H:i'),
            ]
        ]);
    }
}
