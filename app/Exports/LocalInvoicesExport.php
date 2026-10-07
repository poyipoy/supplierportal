<?php

namespace App\Exports;

use App\Contracts\AcceptsExportOptions;
use App\Contracts\TracksExportProgress;
use App\Exports\Advanced\Concerns\UsesColumnCatalog;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Support\BusinessTime;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LocalInvoicesExport implements AcceptsExportOptions, FromQuery, HasLocalePreference, TracksExportProgress, WithCustomChunkSize, WithCustomCsvSettings, WithCustomQuerySize, WithHeadings, WithMapping
{
    use InteractsWithExportProgress;
    use UsesColumnCatalog;

    public function __construct(private int $actorId, private array $filters = [], private bool $payments = false) {}

    public function query()
    {
        $actor = User::findOrFail($this->actorId);
        abort_unless($actor->is_active && ($actor->isLocalOperator() || $actor->isAdmin()), 403);

        $query = app(InvoiceQuery::class)->filtered($this->filters, payments: $this->payments)->orderBy('id');
        if ($this->hasExportOptions()) {
            $query->setEagerLoads([])->with($this->catalogEagerLoads());
        }

        return $query;
    }

    public function headings(): array
    {
        if ($this->hasExportOptions()) {
            return $this->catalogHeadings();
        }

        return [__('exports.headings.submission'), __('exports.headings.receipt'), __('exports.headings.invoice'), __('exports.headings.nomor_faktur_pajak'), __('exports.headings.po_reference'), __('exports.headings.supplier'), __('exports.headings.currency'), __('exports.headings.invoice_amount'), __('exports.headings.ppn'), __('exports.headings.status'), __('exports.headings.submitted').' ('.BusinessTime::label().')', __('exports.headings.approved').' ('.BusinessTime::label().')', __('exports.headings.payment_term_days'), __('exports.headings.due_date'), __('exports.headings.scheduled_payment'), __('exports.headings.completed').' ('.BusinessTime::label().')'];
    }

    public function map($invoice): array
    {
        if ($this->hasExportOptions()) {
            return $this->catalogMap($invoice);
        }

        return array_map(fn ($value) => SpreadsheetCellSanitizer::text((string) $value), [
            $invoice->submission_number, $invoice->receipt?->receipt_number, $invoice->invoice_number, $invoice->tax_invoice_number, $invoice->po_number,
            $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name, $invoice->currency,
            $invoice->invoice_amount, $invoice->tax_amount, StatusHelper::localInvoiceLabel($invoice->status), $invoice->submitted_at ? BusinessTime::format($invoice->submitted_at, 'Y-m-d', false) : null,
            $invoice->approved_at ? BusinessTime::format($invoice->approved_at, 'Y-m-d', false) : null, $invoice->payment_term_days_snapshot, $invoice->due_date?->format('Y-m-d'),
            $invoice->scheduled_payment_date?->format('Y-m-d'), $invoice->completed_at ? BusinessTime::format($invoice->completed_at, 'Y-m-d', false) : null,
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
