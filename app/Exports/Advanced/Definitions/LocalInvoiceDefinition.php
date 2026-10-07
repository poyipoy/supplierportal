<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\LocalInvoicesExport;
use App\Http\Requests\Export\Filters\LocalInvoiceExportFilters;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;

final class LocalInvoiceDefinition implements ExportDefinition
{
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return LocalInvoicesExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && in_array($user->role, $this->exportKey === 'finance.local-invoices' ? ['finance', 'admin'] : ['accounting', 'finance', 'admin'], true);
    }

    public function filterSchema(): array
    {
        return LocalInvoiceExportFilters::schema($this->exportKey === 'accounting.local-invoices');
    }

    public static function columns(): array
    {
        $audiences = ['finance', 'accounting'];
        // Preserve the legacy string values, including decimal and null formatting.
        $text = fn ($v) => SpreadsheetCellSanitizer::text((string) $v);
        $date = fn ($v) => $text($v ? BusinessTime::format($v, 'Y-m-d', false) : null);
        $suffix = fn () => ' ('.BusinessTime::label().')';

        return self::$catalog ??= [
            new ExportColumn('submission', 'submission', fn ($i) => $i->submission_number, required: true, audiences: $audiences),
            new ExportColumn('receipt', 'receipt', fn ($i) => $i->receipt?->receipt_number, audiences: $audiences, with: ['receipt']),
            new ExportColumn('invoice', 'invoice', fn ($i) => $i->invoice_number, audiences: $audiences),
            new ExportColumn('tax_invoice_number', 'nomor_faktur_pajak', fn ($i) => $i->tax_invoice_number, audiences: $audiences),
            new ExportColumn('po_reference', 'po_reference', fn ($i) => $i->po_number, audiences: $audiences),
            new ExportColumn('supplier', 'supplier', fn ($i) => $i->supplier->supplier?->company_name ?: $i->supplier->name, audiences: $audiences, with: ['supplier.supplier']),
            new ExportColumn('currency', 'currency', fn ($i) => $i->currency, audiences: $audiences),
            new ExportColumn('invoice_amount', 'invoice_amount', fn ($i) => $text($i->invoice_amount), type: ColumnType::Money, audiences: $audiences),
            new ExportColumn('ppn', 'ppn', fn ($i) => $text($i->tax_amount), type: ColumnType::Money, audiences: $audiences),
            new ExportColumn('status', 'status', fn ($i) => StatusHelper::localInvoiceLabel($i->status), audiences: $audiences),
            new ExportColumn('submitted', 'submitted', fn ($i) => $date($i->submitted_at), type: ColumnType::Date, audiences: $audiences, headingSuffix: $suffix),
            new ExportColumn('approved', 'approved', fn ($i) => $date($i->approved_at), type: ColumnType::Date, audiences: $audiences, headingSuffix: $suffix),
            new ExportColumn('payment_term_days', 'payment_term_days', fn ($i) => $text($i->payment_term_days_snapshot), type: ColumnType::Number, audiences: $audiences),
            new ExportColumn('due_date', 'due_date', fn ($i) => $text($i->due_date?->format('Y-m-d')), type: ColumnType::Date, audiences: $audiences),
            new ExportColumn('scheduled_payment', 'scheduled_payment', fn ($i) => $text($i->scheduled_payment_date?->format('Y-m-d')), type: ColumnType::Date, audiences: $audiences),
            new ExportColumn('completed', 'completed', fn ($i) => $date($i->completed_at), type: ColumnType::Date, audiences: $audiences, headingSuffix: $suffix),
        ];
    }
}
