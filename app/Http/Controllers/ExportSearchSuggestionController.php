<?php

namespace App\Http\Controllers;

use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Http\Requests\Export\Filters\LocalInvoiceExportFilters;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Http\Requests\Export\Filters\RequisitionExportFilters;
use App\Http\Requests\Export\Filters\ShipmentExportFilters;
use App\Models\LocalInvoice;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Support\Export\ExportDefinitions;
use App\Support\StatusHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExportSearchSuggestionController extends Controller
{
    private const MAX_TOTAL = 20;

    private const MAX_DOCUMENTS = 12;

    private const MAX_MATERIALS = 8;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'export_key' => ['required', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $exportKey = $validated['export_key'];

        try {
            $definition = ExportDefinitions::get($exportKey);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless($definition->authorize($user), 403);

        $isSupplier = $user->isSupplier() || str_starts_with($exportKey, 'supplier.');
        $forcedSupplierId = $isSupplier ? (int) $user->getKey() : null;
        $term = trim((string) ($validated['q'] ?? ''));
        $like = $term !== '' ? '%'.addcslashes($term, '\\%_').'%' : null;

        $contextData = collect($request->query())
            ->except(['export_key', 'q', 'search', 'po_number', 'pr_number', 'shipment_number', 'options'])
            ->all();

        $items = match ($definition->exportClass()) {
            PurchaseOrdersExport::class => $this->purchaseOrderSuggestions($contextData, $isSupplier, $forcedSupplierId, $like),
            QuotationsExport::class => $this->quotationSuggestions($contextData, $isSupplier, $forcedSupplierId, $like),
            RequisitionsExport::class => $this->requisitionSuggestions($contextData, $like),
            ShipmentsExport::class => $this->shipmentSuggestions($contextData, $isSupplier, $forcedSupplierId, $like),
            LocalInvoicesExport::class => $this->localInvoiceSuggestions($contextData, str_starts_with($exportKey, 'accounting.'), $like),
            default => [],
        };

        return response()
            ->json(['data' => $this->deduplicate($items)])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * @param  array<string, mixed>  $contextData
     * @return list<array<string, mixed>>
     */
    private function purchaseOrderSuggestions(array $contextData, bool $isSupplier, ?int $forcedSupplierId, ?string $like): array
    {
        $filters = $this->safeValidate(fn () => PurchaseOrderExportFilters::validated(
            Request::create('/', 'GET', [...$contextData, 'options' => []]),
            supplier: $isSupplier,
        ));
        if ($forcedSupplierId !== null) {
            $filters['supplier_id'] = $forcedSupplierId;
        }

        $query = PurchaseOrder::query()->with([
            'supplier:id,name',
            'quotations.purchaseRequisition:id,pr_number,period_id',
            'quotations.purchaseRequisition.period:id,name,month,year',
        ]);
        PurchaseOrderExportFilters::apply($query, $filters);

        if ($like !== null) {
            $query->where(function (Builder $q) use ($like, $isSupplier): void {
                $q->where('po_number', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('quotations.purchaseRequisition', fn (Builder $pr) => $pr->where('pr_number', 'like', $like)
                        ->orWhereHas('period', fn (Builder $period) => $period->where('name', 'like', $like)));

                if (! $isSupplier) {
                    $q->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like));
                }
            });
        }

        return $query->orderByDesc('id')
            ->limit(18)
            ->get()
            ->map(function (PurchaseOrder $po) use ($isSupplier): array {
                $prRef = $po->pr_reference;
                $subParts = array_filter([
                    ! $isSupplier ? $po->supplier?->name : null,
                    $prRef !== '-' ? $prRef : null,
                    $po->estimated_arrival?->format('Y-m-d'),
                ]);

                return [
                    'value' => (string) $po->po_number,
                    'label' => (string) $po->po_number,
                    'sublabel' => $subParts !== [] ? implode(' · ', $subParts) : null,
                    'badge' => StatusHelper::poLabel($po->status, $po->is_overdue),
                    'badgeTone' => $this->normalizeTone(StatusHelper::poTone($po->status, $po->is_overdue)),
                    'searchKeywords' => implode(' ', array_filter([
                        $po->po_number,
                        ! $isSupplier ? $po->supplier?->name : null,
                        $prRef !== '-' ? $prRef : null,
                        $po->notes,
                    ])),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $contextData
     * @return list<array<string, mixed>>
     */
    private function quotationSuggestions(array $contextData, bool $isSupplier, ?int $forcedSupplierId, ?string $like): array
    {
        $filters = $this->safeValidate(fn () => QuotationExportFilters::validated(
            Request::create('/', 'GET', $contextData),
            supplier: $isSupplier,
        ));

        $statuses = [
            Quotation::STATUS_SUBMITTED,
            Quotation::STATUS_REVISION_REQUESTED,
            Quotation::STATUS_ACCEPTED,
            Quotation::STATUS_REJECTED,
            Quotation::STATUS_ALL_UNAVAILABLE,
        ];
        if ($isSupplier) {
            array_unshift($statuses, Quotation::STATUS_DRAFT);
        }

        $baseQuotations = Quotation::query()->whereIn('status', $statuses);
        QuotationExportFilters::apply($baseQuotations, $filters, $forcedSupplierId);

        $prQuery = (clone $baseQuotations)->with([
            'purchaseRequisition:id,pr_number,period_id',
            'purchaseRequisition.period:id,name,month,year',
            'supplier:id,name',
            'supplier.supplier:id,user_id,company_name',
        ]);

        if ($like !== null) {
            $prQuery->where(function (Builder $q) use ($like, $isSupplier): void {
                $q->whereHas('purchaseRequisition', fn (Builder $pr) => $pr->where('pr_number', 'like', $like)
                    ->orWhereHas('period', fn (Builder $period) => $period->where('name', 'like', $like)));

                if (! $isSupplier) {
                    $q->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like)
                        ->orWhereHas('supplier', fn (Builder $profile) => $profile->where('company_name', 'like', $like)));
                }
            });
        }

        $items = [];
        $seenPr = [];
        foreach ($prQuery->orderByDesc('id')->limit(30)->get() as $quotation) {
            $pr = $quotation->purchaseRequisition;
            $prNumber = trim((string) ($pr?->pr_number ?? ''));
            if ($prNumber === '' || isset($seenPr[mb_strtolower($prNumber)])) {
                continue;
            }
            $seenPr[mb_strtolower($prNumber)] = true;

            $supplierName = ! $isSupplier
                ? ($quotation->supplier?->supplier?->company_name ?: $quotation->supplier?->name)
                : null;
            $subParts = array_filter([
                $pr?->period?->display_label ?? $pr?->period?->name,
                $supplierName,
            ]);

            $items[] = [
                'value' => $prNumber,
                'label' => $prNumber,
                'sublabel' => $subParts !== [] ? implode(' · ', $subParts) : null,
                'badge' => 'PR',
                'badgeTone' => 'neutral',
                'searchKeywords' => implode(' ', array_filter([$prNumber, ...$subParts])),
            ];

            if (count($items) >= self::MAX_DOCUMENTS) {
                break;
            }
        }

        $materialQuery = PrItem::query()
            ->whereNotNull('material_name')
            ->where('material_name', '!=', '')
            ->whereIn('id', QuotationItem::query()
                ->select('pr_item_id')
                ->whereIn('quotation_id', (clone $baseQuotations)->select('id')));

        if ($like !== null) {
            $materialQuery->where('material_name', 'like', $like);
        }

        foreach ($this->distinctMaterials($materialQuery) as $material) {
            $items[] = $this->materialOption($material);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $contextData
     * @return list<array<string, mixed>>
     */
    private function requisitionSuggestions(array $contextData, ?string $like): array
    {
        $filters = $this->safeValidate(fn () => RequisitionExportFilters::validated(
            Request::create('/', 'GET', $contextData),
        ));

        $baseRequisitions = PurchaseRequisition::query()->whereNotNull('pr_number')->where('pr_number', '!=', '');
        RequisitionExportFilters::apply($baseRequisitions, $filters);

        $prQuery = (clone $baseRequisitions)->with([
            'period:id,name,month,year',
            'creator:id,name',
        ]);

        if ($like !== null) {
            $prQuery->where(fn (Builder $q) => $q->where('pr_number', 'like', $like)
                ->orWhereHas('period', fn (Builder $period) => $period->where('name', 'like', $like))
                ->orWhereHas('creator', fn (Builder $creator) => $creator->where('name', 'like', $like)));
        }

        $items = $prQuery->orderByDesc('id')
            ->limit(self::MAX_DOCUMENTS)
            ->get()
            ->map(function (PurchaseRequisition $pr): array {
                $subParts = array_filter([
                    $pr->period?->display_label ?? $pr->period?->name,
                    $pr->creator?->name,
                ]);
                $status = (string) $pr->status;

                return [
                    'value' => (string) $pr->pr_number,
                    'label' => (string) $pr->pr_number,
                    'sublabel' => $subParts !== [] ? implode(' · ', $subParts) : null,
                    'badge' => StatusHelper::prLabel($status),
                    'badgeTone' => $this->normalizeTone(StatusHelper::prTone($status)),
                    'searchKeywords' => implode(' ', array_filter([$pr->pr_number, ...$subParts])),
                ];
            })
            ->all();

        $materialQuery = PrItem::query()
            ->whereNotNull('material_name')
            ->where('material_name', '!=', '')
            ->whereIn('pr_id', (clone $baseRequisitions)->select('id'));

        if ($like !== null) {
            $materialQuery->where('material_name', 'like', $like);
        }

        foreach ($this->distinctMaterials($materialQuery) as $material) {
            $items[] = $this->materialOption($material);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $contextData
     * @return list<array<string, mixed>>
     */
    private function shipmentSuggestions(array $contextData, bool $isSupplier, ?int $forcedSupplierId, ?string $like): array
    {
        $filters = $this->safeValidate(fn () => ShipmentExportFilters::validated(
            Request::create('/', 'GET', $contextData),
            supplier: $isSupplier,
        ));
        if ($forcedSupplierId !== null) {
            $filters['supplier_id'] = $forcedSupplierId;
        }

        $baseShipments = Shipment::query();
        ShipmentExportFilters::apply($baseShipments, $filters);

        $shipmentQuery = (clone $baseShipments)->with([
            'supplier:id,name',
            'items.purchaseOrder:id,po_number',
        ]);

        if ($like !== null) {
            $shipmentQuery->where(function (Builder $q) use ($like, $isSupplier): void {
                $q->where('shipment_number', 'like', $like)
                    ->orWhere('supplier_reference', 'like', $like)
                    ->orWhere('invoice_number', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('items.purchaseOrder', fn (Builder $po) => $po->where('po_number', 'like', $like));

                if (! $isSupplier) {
                    $q->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like));
                }
            });
        }

        $items = $shipmentQuery->orderByDesc('shipment_date')
            ->orderByDesc('id')
            ->limit(self::MAX_DOCUMENTS)
            ->get()
            ->map(function (Shipment $shipment) use ($isSupplier): array {
                $pos = $shipment->items
                    ->map(fn (ShipmentItem $item) => $item->purchaseOrder?->po_number)
                    ->filter()
                    ->unique()
                    ->implode(', ');
                $subParts = array_filter([
                    ! $isSupplier ? $shipment->supplier?->name : null,
                    $pos !== '' ? $pos : null,
                ]);
                $status = (string) $shipment->status;

                return [
                    'value' => (string) $shipment->shipment_number,
                    'label' => (string) $shipment->shipment_number,
                    'sublabel' => $subParts !== [] ? implode(' · ', $subParts) : null,
                    'badge' => StatusHelper::shipmentLabel($status),
                    'badgeTone' => $this->normalizeTone(StatusHelper::shipmentTone($status)),
                    'searchKeywords' => implode(' ', array_filter([
                        $shipment->shipment_number,
                        ! $isSupplier ? $shipment->supplier?->name : null,
                        $pos,
                        $shipment->supplier_reference,
                        $shipment->invoice_number,
                    ])),
                ];
            })
            ->all();

        $materialQuery = PrItem::query()
            ->whereNotNull('material_name')
            ->where('material_name', '!=', '')
            ->whereIn('id', QuotationItem::query()
                ->select('pr_item_id')
                ->whereIn('id', ShipmentItem::query()
                    ->select('quotation_item_id')
                    ->whereIn('shipment_id', (clone $baseShipments)->select('id'))));

        if ($like !== null) {
            $materialQuery->where('material_name', 'like', $like);
        }

        foreach ($this->distinctMaterials($materialQuery) as $material) {
            $items[] = $this->materialOption($material);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $contextData
     * @return list<array<string, mixed>>
     */
    private function localInvoiceSuggestions(array $contextData, bool $accounting, ?string $like): array
    {
        $filters = $this->safeValidate(fn () => LocalInvoiceExportFilters::validated(
            Request::create('/', 'GET', $accounting ? ['report' => $contextData['report'] ?? 'register', ...$contextData] : $contextData),
            accounting: $accounting,
        ));
        unset($filters['q']);

        $payments = $accounting && ($filters['report'] ?? 'register') === 'payments';
        $query = app(InvoiceQuery::class)->filtered($filters, payments: $payments);

        if ($like !== null) {
            $query->where(fn (Builder $q) => $q->where('invoice_number', 'like', $like)
                ->orWhere('submission_number', 'like', $like)
                ->orWhere('po_number', 'like', $like)
                ->orWhere('manual_po_number', 'like', $like)
                ->orWhereHas('receipt', fn (Builder $receipt) => $receipt->where('receipt_number', 'like', $like))
                ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $like)
                    ->orWhereHas('supplier', fn (Builder $profile) => $profile->where('company_name', 'like', $like))));
        }

        return $query->orderByDesc('id')
            ->limit(18)
            ->get()
            ->map(function (LocalInvoice $invoice): array {
                $primary = trim((string) ($invoice->invoice_number ?: $invoice->submission_number));
                $poRef = $invoice->po_number ?: $invoice->manual_po_number;
                $supplierName = $invoice->supplier?->supplier?->company_name ?: $invoice->supplier?->name;
                $subParts = array_filter([
                    $invoice->invoice_number ? $invoice->submission_number : null,
                    $poRef ? 'PO '.$poRef : null,
                    $supplierName,
                ]);
                $status = (string) $invoice->status;

                return [
                    'value' => $primary,
                    'label' => $primary,
                    'sublabel' => $subParts !== [] ? implode(' · ', $subParts) : null,
                    'badge' => StatusHelper::localInvoiceLabel($status),
                    'badgeTone' => $this->normalizeTone(StatusHelper::localInvoiceTone($status)),
                    'searchKeywords' => implode(' ', array_filter([
                        $invoice->invoice_number,
                        $invoice->submission_number,
                        $poRef,
                        $invoice->receipt?->receipt_number,
                        $supplierName,
                    ])),
                ];
            })
            ->all();
    }

    /**
     * @return list<string>
     */
    private function distinctMaterials(Builder $query): array
    {
        return $query->selectRaw('MIN(material_name) as material_name')
            ->groupByRaw('LOWER(TRIM(material_name))')
            ->orderBy('material_name')
            ->limit(self::MAX_MATERIALS)
            ->pluck('material_name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function materialOption(string $material): array
    {
        $materialLabel = __('exports.headings.material');

        return [
            'value' => $material,
            'label' => $material,
            'sublabel' => $materialLabel,
            'badge' => mb_strtoupper($materialLabel),
            'badgeTone' => 'neutral',
            'searchKeywords' => $material.' '.$materialLabel,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function safeValidate(callable $callback): array
    {
        try {
            return $callback();
        } catch (ValidationException|HttpExceptionInterface) {
            return [];
        }
    }

    private function normalizeTone(string $tone): string
    {
        return in_array($tone, ['success', 'warning', 'error'], true) ? $tone : 'neutral';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $items): array
    {
        $unique = [];
        $seen = [];

        foreach ($items as $item) {
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
            if (count($unique) >= self::MAX_TOTAL) {
                break;
            }
        }

        return $unique;
    }
}
