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

class LocalInvoicesExport implements FromQuery, TracksExportProgress, WithCustomChunkSize, WithCustomQuerySize, WithHeadings, WithMapping
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
        return ['Submission', 'Receipt', 'Invoice', 'PO Reference', 'Supplier', 'Currency', 'Invoice Amount', 'PPN', 'Status', 'Submitted', 'Approved', 'Payment Term Days', 'Due Date', 'Scheduled Payment', 'Completed'];
    }

    public function map($invoice): array
    {
        return array_map(fn ($value) => SpreadsheetCellSanitizer::text((string) $value), [
            $invoice->submission_number, $invoice->receipt?->receipt_number, $invoice->invoice_number, $invoice->po_number,
            $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name, $invoice->currency,
            $invoice->invoice_amount, $invoice->tax_amount, $invoice->status, $invoice->submitted_at?->format('Y-m-d'),
            $invoice->approved_at?->format('Y-m-d'), $invoice->payment_term_days_snapshot, $invoice->due_date?->format('Y-m-d'),
            $invoice->scheduled_payment_date?->format('Y-m-d'), $invoice->completed_at?->format('Y-m-d'),
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
