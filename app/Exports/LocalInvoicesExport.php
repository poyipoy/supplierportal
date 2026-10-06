<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LocalInvoicesExport implements \Illuminate\Contracts\Translation\HasLocalePreference, FromQuery, TracksExportProgress, WithCustomChunkSize, WithCustomQuerySize, WithHeadings, WithMapping
{
    use InteractsWithExportProgress;

    public function __construct(private int $actorId, private array $filters = [], private bool $payments = false) {}

    public function query()
    {
        $actor = User::findOrFail($this->actorId);
        abort_unless($actor->is_active && ($actor->isLocalOperator() || $actor->isAdmin()), 403);

        return app(InvoiceQuery::class)->filtered($this->filters, payments: $this->payments)->orderBy('id');
    }

    public function headings(): array
    {
        return [__('exports.headings.submission'), __('exports.headings.receipt'), __('exports.headings.invoice'), __('exports.headings.nomor_faktur_pajak'), __('exports.headings.po_reference'), __('exports.headings.supplier'), __('exports.headings.currency'), __('exports.headings.invoice_amount'), __('exports.headings.ppn'), __('exports.headings.status'), __('exports.headings.submitted').' ('.\App\Support\BusinessTime::label().')', __('exports.headings.approved').' ('.\App\Support\BusinessTime::label().')', __('exports.headings.payment_term_days'), __('exports.headings.due_date'), __('exports.headings.scheduled_payment'), __('exports.headings.completed').' ('.\App\Support\BusinessTime::label().')'];
    }

    public function map($invoice): array
    {
        return array_map(fn ($value) => SpreadsheetCellSanitizer::text((string) $value), [
            $invoice->submission_number, $invoice->receipt?->receipt_number, $invoice->invoice_number, $invoice->tax_invoice_number, $invoice->po_number,
            $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name, $invoice->currency,
            $invoice->invoice_amount, $invoice->tax_amount, \App\Support\StatusHelper::localInvoiceLabel($invoice->status), $invoice->submitted_at ? \App\Support\BusinessTime::format($invoice->submitted_at, 'Y-m-d', false) : null,
            $invoice->approved_at ? \App\Support\BusinessTime::format($invoice->approved_at, 'Y-m-d', false) : null, $invoice->payment_term_days_snapshot, $invoice->due_date?->format('Y-m-d'),
            $invoice->scheduled_payment_date?->format('Y-m-d'), $invoice->completed_at ? \App\Support\BusinessTime::format($invoice->completed_at, 'Y-m-d', false) : null,
        ]);
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function progressTotalRows(): int
    {
        return $this->querySize();
    }
}
