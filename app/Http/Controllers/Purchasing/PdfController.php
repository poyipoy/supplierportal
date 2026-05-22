<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfController extends Controller
{
    /**
     * Generate PDF dokumen Purchase Order.
     */
    public function purchaseOrder($id)
    {
        $po = PurchaseOrder::with([
            'supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequirement.period',
            'quotations.exchange_rate',
            'creator',
        ])->findOrFail($id);

        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        $pdf = Pdf::loadView('pdf.po-pdf', compact('po', 'quotationRates'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('PO_' . str_replace('/', '-', $po->po_number) . '.pdf');
    }

    /**
     * Generate PDF Berita Acara Inspeksi QC.
     */
    public function qcInspection($id)
    {
        $inspection = QcInspection::with([
            'purchaseOrder.quotation.supplier',
            'purchaseOrder.quotation.purchaseRequirement.period',
            'inspector',
            'items.prItem',
        ])->findOrFail($id);

        $pdf = Pdf::loadView('pdf.qc-inspection-pdf', compact('inspection'))
            ->setPaper('a4', 'portrait');

        $filename = 'QC_Inspection_PO_' . str_replace('/', '-', $inspection->purchaseOrder->po_number) . '.pdf';

        return $pdf->download($filename);
    }
}
