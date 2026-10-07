<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\PurchaseOrdersExport;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\StatusHelper;

final class PurchaseOrderDefinition implements ExportDefinition
{
    // Static properties are not part of PHP's serialized instance state
    // (confirmed by the read-only PhaseTwoStaticCatalogProbe).
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return PurchaseOrdersExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && match ($this->exportKey) {
            'purchasing.po' => $user->role === 'purchasing',
            'supplier.po' => $user->isImportEligible(),
            default => false,
        };
    }

    public function filterSchema(): array
    {
        return PurchaseOrderExportFilters::schema($this->exportKey === 'supplier.po');
    }

    public static function columns(): array
    {
        $commercial = ['awards', 'quotations.items.prItem'];

        return self::$catalog ??= [
            new ExportColumn('po_number', 'po_number', fn ($po) => $po->po_number, 22, required: true),
            new ExportColumn('pr_number', 'pr_number', fn ($po) => $po->pr_reference, 28, with: ['quotations.purchaseRequisition.period']),
            new ExportColumn('supplier', 'supplier', fn ($po) => $po->supplier?->name, 25, with: ['supplier']),
            new ExportColumn('material', 'material', fn ($po) => $po->commercialQuotations()->flatMap(fn ($q) => $q->items->map(fn ($i) => $i->prItem?->material_name))->filter()->implode(', ') ?: '-', 40, with: $commercial),
            new ExportColumn('currency', 'currency', fn ($po) => $po->currency ?? '-', 12),
            new ExportColumn('total_amount', 'total_amount', fn ($po) => (float) $po->commercialQuotations()->sum(fn ($q) => $q->items->sum(fn ($i) => $i->resolved_amount)), 16, ColumnType::Money, with: $commercial),
            new ExportColumn('total_idr', 'total_idr', fn ($po) => self::totalIdr($po), 18, ColumnType::Money, with: [...$commercial, 'quotations.exchange_rate']),
            new ExportColumn('est_arrival', 'est_arrival', fn ($po) => $po->estimated_arrival?->format('Y-m-d') ?? '-', 16, ColumnType::Date),
            new ExportColumn('remark', 'remark', fn ($po) => $po->notes, 30),
            new ExportColumn('status', 'status', fn ($po) => StatusHelper::poLabel($po->status, $po->is_overdue), 16),
        ];
    }

    private static function totalIdr(PurchaseOrder $po): float
    {
        $total = 0.0;
        foreach ($po->commercialQuotations() as $quotation) {
            foreach ($quotation->items as $item) {
                $total += $item->resolved_amount * (float) ($quotation->exchange_rate?->rate_to_idr ?? 0);
            }
        }

        return $total;
    }
}
