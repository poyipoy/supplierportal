<?php

namespace App\Http\Controllers\Finance;

use App\Exports\LocalInvoicesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalInvoice\InvoiceFilterRequest;
use App\Models\LocalInvoice;
use App\Models\User;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FinanceInvoiceController extends Controller
{
    public function index(InvoiceFilterRequest $request, InvoiceQuery $query)
    {
        $filters = $request->validated();
        $invoices = $query->filtered($filters)->latest('id')->paginate(25)->withQueryString();

        return view('finance.invoices.index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'suppliers' => User::localEligible()->with('supplier')->orderBy('name')->get(),
            'title' => 'Invoice Register',
        ]);
    }

    public function show(LocalInvoice $invoice)
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'supplier.supplier.bankAccounts',
            'supplier.supplier.activeBankAccount',
            'receipt',
            'revisions.documents',
            'statusHistories.actor',
            'physicalVerifications.actor',
            'currentVerification.verifier',
            'localPurchaseOrder',
            'goodsReceiptHistories.goodsReceipt',
            'voucher.payment.transfers',
        ]);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
            'verification' => $invoice->currentVerification,
        ]);
    }

    public function receivePhysical(Request $request, LocalInvoice $invoice, InvoicePhysicalReceiptService $service)
    {
        $service->recordReceipt($request->user(), $invoice, $request->input('notes'));

        return back()->with('success', 'Physical document receipt recorded successfully. Due date calculated.');
    }

    public function verifySectionA(Request $request, LocalInvoice $invoice, InvoiceVerificationService $service)
    {
        $data = $request->validate([
            'invoice_check' => 'required|in:OK,NOT_OK',
            'invoice_notes' => 'nullable|string|max:1000',
            'tax_invoice_check' => 'required|in:OK,NOT_OK,NOT_APPLICABLE',
            'tax_invoice_notes' => 'nullable|string|max:1000',
            'po_check' => 'required|in:OK,NOT_OK',
            'po_notes' => 'nullable|string|max:1000',
            'delivery_note_check' => 'required|in:OK,NOT_OK,NOT_APPLICABLE',
            'delivery_note_notes' => 'nullable|string|max:1000',
            'gr_check' => 'required|in:OK,NOT_OK',
            'gr_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $verification = $service->verifySectionA($invoice, $data, $request->user());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->to(route('finance.invoices.show', $invoice).'#section-a')
                ->withInput()
                ->withErrors(['general' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Section A (Pemeriksaan Dokumen & Referensi) berhasil disimpan.',
                'section' => 'A',
                'is_section_a_passed' => (bool) $verification->is_section_a_passed,
                'is_section_b_passed' => (bool) $verification->is_section_b_passed,
                'can_approve' => (bool) ($verification->is_section_a_passed && $verification->is_section_b_passed),
            ]);
        }

        return redirect()->to(route('finance.invoices.show', $invoice).'#section-a')
            ->with('success', 'Section A (Pemeriksaan Dokumen & Referensi) berhasil disimpan.');
    }

    public function verifySectionB(Request $request, LocalInvoice $invoice, InvoiceVerificationService $service)
    {
        $data = $request->validate([
            'ppn_status' => 'required|in:SESUAI,TIDAK_SESUAI',
            'verified_ppn' => 'nullable|numeric|min:0',
            'pph_23_applicable' => 'nullable|boolean',
            'pph_23_base' => 'nullable|numeric|min:0',
            'pph_23_rate' => 'nullable|numeric|min:0|max:100',
            'pph_23_amount' => 'nullable|numeric|min:0',
            'pph_4_2_applicable' => 'nullable|boolean',
            'pph_4_2_amount' => 'nullable|numeric|min:0',
            'pph_21_applicable' => 'nullable|boolean',
            'pph_21_amount' => 'nullable|numeric|min:0',
            'tax_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $verification = $service->verifySectionB($invoice, $data, $request->user());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->to(route('finance.invoices.show', $invoice).'#section-b')
                ->withInput()
                ->withErrors(['general' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Section B (Verifikasi Perpajakan) berhasil disimpan.',
                'section' => 'B',
                'is_section_a_passed' => (bool) $verification->is_section_a_passed,
                'is_section_b_passed' => (bool) $verification->is_section_b_passed,
                'can_approve' => (bool) ($verification->is_section_a_passed && $verification->is_section_b_passed),
            ]);
        }

        return redirect()->to(route('finance.invoices.show', $invoice).'#section-b')
            ->with('success', 'Section B (Verifikasi Perpajakan) berhasil disimpan.');
    }

    public function approveReadyToPay(Request $request, LocalInvoice $invoice, InvoiceVerificationService $service)
    {
        $service->lockAndApprove($invoice, $request->user());

        return back()->with('success', 'Invoice verification locked and transitioned to Ready to Pay.');
    }

    public function requestRevision(Request $request, LocalInvoice $invoice, InvoiceVerificationService $service)
    {
        $request->validate(['notes' => 'required|string|max:2000']);
        $service->requestRevision($invoice, $request->input('notes'), $request->user());

        return back()->with('success', 'Revision requested from Supplier.');
    }

    public function reject(Request $request, LocalInvoice $invoice, InvoiceVerificationService $service)
    {
        $data = $request->validate(['notes' => 'required|string|max:2000']);
        $service->reject($invoice, $data['notes'], $request->user());

        return back()->with('success', 'Invoice rejected and its GR reservations released.');
    }

    public function masterInvoice(InvoiceFilterRequest $request, InvoiceQuery $query)
    {
        $filters = $request->validated();
        $invoices = $query->filtered($filters)->latest('id')->paginate(25)->withQueryString();

        return view('finance.master-invoices.index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'suppliers' => User::localEligible()->with('supplier')->orderBy('name')->get(),
        ]);
    }

    public function exportMasterInvoice(InvoiceFilterRequest $request)
    {
        $filters = $request->validated();
        ExportDispatcher::dispatch(
            'Master Invoices Report',
            LocalInvoicesExport::class,
            [$request->user()->id, $filters, false],
            'master-invoices-'.now()->format('Ymd-His').'.xlsx'
        );

        return redirect()->route('exports.index')->with('success', 'Master invoices export queued.');
    }
}
