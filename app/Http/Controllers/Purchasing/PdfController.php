<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use Barryvdh\DomPDF\Facade\Pdf;
use Vinkla\Hashids\Facades\Hashids;

class PdfController extends Controller
{
    /**
     * Generate PDF document Purchase Order.
     */
    public function purchaseOrder($id)
    {
        $realId = $this->resolveId($id);
        abort_if(! $realId, 404);

        $po = PurchaseOrder::with([
            'supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequisition.period',
            'quotations.exchange_rate',
            'creator',
        ])->findOrFail($realId);

        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        $pdf = Pdf::loadView('pdf.po-pdf', compact('po', 'quotationRates'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('PO_' . str_replace('/', '-', $po->po_number) . '.pdf');
    }

    /**
     * Generate PDF QC Inspection Report.
     */
    public function qcInspection($id)
    {
        $realId = $this->resolveId($id);
        abort_if(! $realId, 404);

        $inspection = QcInspection::with([
            'purchaseOrder.supplier',
            'purchaseOrder.quotations.purchaseRequisition.period',
            'inspector',
            'items.prItem',
        ])->findOrFail($realId);

        $pdf = Pdf::loadView('pdf.qc-inspection-pdf', compact('inspection'))
            ->setPaper('a4', 'portrait');

        $filename = 'QC_Inspection_PO_' . str_replace('/', '-', $inspection->purchaseOrder->po_number) . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Resolve either a hashid string or a raw numeric/integer ID to an integer.
     * Handles: int, numeric string, or hashid-encoded string.
     */
    private function resolveId(mixed $id): ?int
    {
        // Already a PHP integer — use directly
        if (is_int($id)) {
            return $id;
        }

        $str = (string) $id;

        // Plain numeric string — use as-is without decoding
        if (ctype_digit($str)) {
            return (int) $str;
        }

        // Hashid string — decode it
        $decoded = Hashids::decode($str);
        return isset($decoded[0]) ? (int) $decoded[0] : null;
    }
}
