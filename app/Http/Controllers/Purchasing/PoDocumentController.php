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
     * Update status dokumen via AJAX.
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
            'pending' => 'Belum Ada',
            'received' => 'Diterima',
            'verified' => 'Diverifikasi',
            'issued' => 'Sudah Diterbitkan',
            'processing' => 'Sedang Diproses',
            'done' => 'Selesai',
        ][$doc->status] ?? $doc->status;

        if ($po?->creator) {
            $po->creator->notify(new SystemNotification(
                'Status Dokumen Diperbarui',
                "Dokumen {$docLabel} pada PO {$po->po_number} telah diperbarui menjadi \"{$statusLabel}\".",
                route('purchasing.purchase-orders.show', $po->id),
                'bi-file-earmark-check text-primary',
                ['category' => NotificationCategory::DOCUMENT]
            ));
        }

        $allDone = $po
            ? $po->documents()->whereIn('status', $completedStatuses)->count() === 4
            : false;

        if ($allDone && !$wasAllDone && $po?->creator) {
            $po->creator->notify(new SystemNotification(
                'Semua Dokumen Impor Lengkap',
                "Semua dokumen impor untuk PO {$po->po_number} telah lengkap. Konfirmasi kedatangan material jika sudah tiba.",
                route('purchasing.purchase-orders.show', $po->id),
                'bi-check2-circle text-success',
                ['category' => NotificationCategory::DOCUMENT]
            ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Status dokumen berhasil diperbarui.',
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
