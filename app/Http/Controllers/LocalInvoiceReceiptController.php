<?php

namespace App\Http\Controllers;

use App\Models\LocalInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class LocalInvoiceReceiptController extends Controller
{
    public function show(LocalInvoice $invoice)
    {
        return response()->view('local-supplier.invoices.receipt', $this->receiptData($invoice))
            ->header('Cache-Control', 'private, no-store');
    }

    /** Printable receipt as a PDF form; same data and authorization as the page. */
    public function pdf(LocalInvoice $invoice)
    {
        $data = $this->receiptData($invoice);

        $pdf = Pdf::loadView('pdf.local-invoice-receipt', $data)->setPaper('a4', 'portrait');

        return $pdf->download('Receipt_'.str_replace('/', '-', (string) $invoice->receipt->receipt_number).'.pdf')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Authorization, QR and portal-specific navigation shared by the receipt
     * page and its PDF.
     *
     * @return array<string, mixed>
     */
    private function receiptData(LocalInvoice $invoice): array
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['receipt', 'supplier.supplier']);
        abort_unless($invoice->receipt, 404);
        Gate::authorize('view', $invoice->receipt);

        $signedUrl = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $invoice->receipt->receipt_number,
            'revision' => $invoice->revision_number,
        ]);
        $qrCode = (new QRCode([
            'outputBase64' => true,
            'scale' => 4,
        ]))->render($signedUrl);

        $user = auth()->user();
        $isPurchasing = request()->routeIs('purchasing.*') || ($user && $user->isPurchasing());
        $isAccounting = request()->routeIs('accounting.*') || ($user && $user->role === 'accounting');
        $isFinance = request()->routeIs('finance.*') || ($user && ($user->isFinance() || $user->isAdmin()));

        if ($isPurchasing) {
            $portal = 'purchasing';
            $detailUrl = route('purchasing.local-invoices.show', $invoice);
            $indexUrl = route('purchasing.local-vendors.index');
            $indexLabel = __('local_procurement.labels.vendor_register');
        } elseif ($isAccounting) {
            $portal = 'accounting';
            $detailUrl = route('accounting.invoices.show', $invoice);
            $indexUrl = route('accounting.invoices.index');
            $indexLabel = __('local_invoice.list.title');
        } elseif ($isFinance) {
            $portal = 'finance';
            $detailUrl = route('finance.invoices.show', $invoice);
            $indexUrl = route('finance.invoices.index');
            $indexLabel = __('local_invoice.list.title');
        } else {
            $portal = 'local-supplier';
            $detailUrl = route('local-supplier.invoices.show', $invoice);
            $indexUrl = route('local-supplier.invoices.index');
            $indexLabel = __('local_invoice.list.title');
        }

        // Purchasing reads receipts through the finance group, which admits it.
        $pdfUrl = match ($portal) {
            'accounting' => route('accounting.invoices.receipt.print', $invoice),
            'finance', 'purchasing' => route('finance.invoices.receipt.print', $invoice),
            default => route('local-supplier.invoices.receipt.print', $invoice),
        };

        return compact(
            'invoice', 'qrCode', 'signedUrl', 'detailUrl', 'indexUrl', 'indexLabel', 'portal', 'pdfUrl'
        );
    }
}
