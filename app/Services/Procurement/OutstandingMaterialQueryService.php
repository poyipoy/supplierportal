<?php

namespace App\Services\Procurement;

use App\Models\PoItemProgressUpdate;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\SupplierPreshipmentProfile;
use App\Models\User;
use App\Support\Procurement\LogisticsTerms;
use App\Support\StatusHelper;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OutstandingMaterialQueryService
{
    public const STATUS_ON_SHIPMENT = 'on_shipment';

    public const STATUS_WAITING_QC = 'waiting_qc';

    public const STATUS_CLAIM_NEEDED = 'claim_needed';

    public const STATUS_OVERDUE = 'overdue';

    /**
     * Build a paginated list of outstanding material lines from open Purchase Orders.
     * Enforces strict supplier scoping whenever $supplier is provided.
     *
     * @param  array{search?: string|null, status?: string|null, supplier_id?: int|null, page?: int|null}  $filters
     */
    public function paginate(?User $supplier = null, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()
            ->whereIn('status', ['active', 'overdue', 'waiting_qc', 'claim_needed'])
            ->with([
                'supplier.supplier',
                'awards.latestProgressUpdate',
                'quotations.items.prItem',
                'quotations.purchaseRequisition',
                'quotations.exchange_rate',
                'shipmentItems.shipment.preshipmentInspection',
                'shipmentItems.shipment.documents',
                'shipmentItems.qcItems.inspection.materialClaims',
            ])
            ->orderByRaw('estimated_arrival IS NULL ASC')
            ->orderBy('estimated_arrival')
            ->orderByDesc('created_at');

        if ($supplier !== null) {
            $query->where('supplier_id', $supplier->id);
        } elseif (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        $purchaseOrders = $query->get();

        $supplierIds = $purchaseOrders->pluck('supplier_id')->filter()->unique()->values();
        $preshipmentProfiles = $supplierIds->isEmpty()
            ? collect()
            : SupplierPreshipmentProfile::query()
                ->whereIn('supplier_id', $supplierIds)
                ->get()
                ->keyBy('supplier_id');

        $rows = new Collection;

        foreach ($purchaseOrders as $po) {
            $fulfillmentByItem = $po->fulfillmentProjection()['items'] ?? [];
            $awardsByQItem = $po->awards->keyBy('quotation_item_id');
            $defaultProfilePort = $preshipmentProfiles->get($po->supplier_id)?->exporter_port_of_loading;
            $supplierName = $po->supplier?->supplier?->company_name
                ?? $po->supplier?->name
                ?? '-';

            foreach ($po->orderedLines() as $line) {
                $quotationItem = $line->quotationItem;
                $qItemId = (int) $quotationItem->id;
                $fulfillment = $fulfillmentByItem[$qItemId] ?? [
                    'ordered_qty' => $line->qty,
                    'accepted_qty' => 0,
                    'in_transit_qty' => 0,
                    'waiting_qc_qty' => 0,
                    'unresolved_ng_qty' => 0,
                    'remaining_qty' => $line->qty,
                ];

                $orderedQty = (int) ($fulfillment['ordered_qty'] ?? $line->qty);
                $acceptedQty = (int) ($fulfillment['accepted_qty'] ?? 0);
                if ($orderedQty > 0 && $acceptedQty >= $orderedQty) {
                    continue;
                }

                $prItem = $quotationItem->prItem;
                $award = $line->award ?? $awardsByQItem->get($qItemId);

                [$statusKey, $statusLabel, $statusTone] = $this->resolveRowStatus($fulfillment, $award?->latestProgressUpdate?->status);

                $itemShipments = $this->resolveRelevantShipments($po, $qItemId);
                $portOfLoading = $this->resolvePortOfLoading($itemShipments, $defaultProfilePort);
                $portCode = $po->port_of_discharge
                    ?: $quotationItem->quotation?->purchaseRequisition?->port_of_discharge
                    ?: $po->quotations->first()?->purchaseRequisition?->port_of_discharge;
                $portOfDischarge = ($portCode !== null && trim((string) $portCode) !== '')
                    ? LogisticsTerms::portLabel((string) $portCode)
                    : '-';

                $estArrival = $this->resolveEstimatedArrival($itemShipments, $po);
                $actualArrival = $this->resolveActualArrival($itemShipments, $po);
                $invoiceReferences = $this->resolveInvoiceReferences($itemShipments);
                $invoiceReferenceSearch = collect($invoiceReferences)
                    ->flatMap(fn (array $entry) => [$entry['invoice_number'], $entry['supplier_reference']])
                    ->filter()
                    ->implode(' ');

                $rows->push([
                    'po' => $po,
                    'po_number' => $po->po_number,
                    'invoice_references' => $invoiceReferences,
                    'invoice_reference_search' => $invoiceReferenceSearch,
                    'supplier_id' => (int) $po->supplier_id,
                    'supplier_name' => $supplierName,
                    'material_name' => $prItem?->material_name ?? '-',
                    'dimension' => $prItem ? $prItem->dimension_label : '-',
                    'status_key' => $statusKey,
                    'status_label' => $statusLabel,
                    'status_tone' => $statusTone,
                    'is_overdue' => (bool) $po->is_overdue,
                    'port_of_loading' => $portOfLoading,
                    'port_of_discharge' => $portOfDischarge,
                    'estimated_arrival' => $estArrival,
                    'actual_arrival' => $actualArrival,
                ]);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(function (array $row) use ($needle): bool {
                foreach (['po_number', 'invoice_reference_search', 'supplier_name', 'material_name', 'dimension', 'port_of_loading', 'port_of_discharge'] as $field) {
                    if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $statusFilter = trim((string) ($filters['status'] ?? ''));
        if ($statusFilter === self::STATUS_OVERDUE) {
            $rows = $rows->filter(fn (array $row) => (bool) ($row['is_overdue'] ?? false));
        } elseif ($statusFilter !== '') {
            $rows = $rows->filter(fn (array $row) => $row['status_key'] === $statusFilter);
        }

        $values = $rows->values();
        $page = max(1, (int) ($filters['page'] ?? request()->integer('page', 1)));
        $slice = $values->forPage($page, $perPage)->values();

        return (new LengthAwarePaginator(
            $slice,
            $values->count(),
            $perPage,
            $page,
            ['path' => request()->url()]
        ))->withQueryString();
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return [
            self::STATUS_OVERDUE => StatusHelper::poLabel('overdue'),
            self::STATUS_ON_SHIPMENT => __('purchasing.outstanding_materials.status_on_shipment'),
            self::STATUS_WAITING_QC => __('purchasing.outstanding_materials.status_waiting_qc'),
            self::STATUS_CLAIM_NEEDED => __('purchasing.outstanding_materials.status_claim_needed'),
            PoItemProgressUpdate::STATUS_ORDER_CONFIRMED => StatusHelper::materialProgressLabel(PoItemProgressUpdate::STATUS_ORDER_CONFIRMED),
            PoItemProgressUpdate::STATUS_MATERIAL_PREPARATION => StatusHelper::materialProgressLabel(PoItemProgressUpdate::STATUS_MATERIAL_PREPARATION),
            PoItemProgressUpdate::STATUS_ON_PRODUCTION => StatusHelper::materialProgressLabel(PoItemProgressUpdate::STATUS_ON_PRODUCTION),
            PoItemProgressUpdate::STATUS_READY_TO_SHIP => StatusHelper::materialProgressLabel(PoItemProgressUpdate::STATUS_READY_TO_SHIP),
        ];
    }

    /**
     * @param  array<string, int|float|bool>  $fulfillment
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveRowStatus(array $fulfillment, ?string $latestProgressStatus): array
    {
        if ((int) ($fulfillment['in_transit_qty'] ?? 0) > 0) {
            return [
                self::STATUS_ON_SHIPMENT,
                __('purchasing.outstanding_materials.status_on_shipment'),
                'info',
            ];
        }

        if ((int) ($fulfillment['waiting_qc_qty'] ?? 0) > 0) {
            return [
                self::STATUS_WAITING_QC,
                __('purchasing.outstanding_materials.status_waiting_qc'),
                'warning',
            ];
        }

        if ((int) ($fulfillment['unresolved_ng_qty'] ?? 0) > 0) {
            return [
                self::STATUS_CLAIM_NEEDED,
                __('purchasing.outstanding_materials.status_claim_needed'),
                'error',
            ];
        }

        $normalized = ($latestProgressStatus !== null && $latestProgressStatus !== PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION)
            ? $latestProgressStatus
            : PoItemProgressUpdate::STATUS_ORDER_CONFIRMED;

        return [
            $normalized,
            StatusHelper::materialProgressLabel($normalized),
            StatusHelper::materialProgressTone($normalized),
        ];
    }

    /**
     * @return Collection<int, Shipment>
     */
    private function resolveRelevantShipments(PurchaseOrder $po, int $quotationItemId): Collection
    {
        return $po->shipmentItems
            ->where('quotation_item_id', $quotationItemId)
            ->pluck('shipment')
            ->filter(fn ($shipment) => $shipment instanceof Shipment && $shipment->status !== Shipment::STATUS_CANCELLED)
            ->unique('id')
            ->sortByDesc('id')
            ->values();
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    private function resolvePortOfLoading(Collection $shipments, ?string $fallbackProfilePort): string
    {
        foreach ($shipments as $shipment) {
            $port = trim((string) ($shipment->preshipmentInspection?->exporter_port_of_loading ?? ''));
            if ($port !== '') {
                return $port;
            }
        }

        $fallback = trim((string) ($fallbackProfilePort ?? ''));

        return $fallback !== '' ? $fallback : '-';
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    private function resolveEstimatedArrival(Collection $shipments, PurchaseOrder $po): ?CarbonInterface
    {
        foreach ($shipments as $shipment) {
            if (in_array($shipment->status, [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED], true) && $shipment->estimated_arrival_date !== null) {
                return $shipment->estimated_arrival_date;
            }
        }

        return $po->estimated_arrival;
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    private function resolveActualArrival(Collection $shipments, PurchaseOrder $po): ?CarbonInterface
    {
        foreach ($shipments as $shipment) {
            if ($shipment->status === Shipment::STATUS_ARRIVED && $shipment->actual_arrival_date !== null) {
                return $shipment->actual_arrival_date;
            }
        }

        // Only legacy PO-level arrivals without shipment items may fall back to PO actual_arrival.
        return $po->shipmentItems->isEmpty() ? $po->actual_arrival : null;
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return list<array{invoice_number: string, supplier_reference: string}>
     */
    private function resolveInvoiceReferences(Collection $shipments): array
    {
        $entries = [];
        $seen = [];

        foreach ($shipments as $shipment) {
            $invoiceNumber = trim((string) ($shipment->invoice_number ?? ''));
            if ($invoiceNumber === '') {
                $invoiceDoc = $shipment->documents->firstWhere('doc_type', ShipmentDocument::DOC_TYPE_INVOICE);
                $invoiceNumber = trim((string) ($invoiceDoc?->document_number ?? ''));
            }

            $supplierReference = trim((string) ($shipment->supplier_reference ?? ''));
            if ($invoiceNumber === '' && $supplierReference === '') {
                continue;
            }

            $dedupeKey = mb_strtolower($invoiceNumber.'|'.$supplierReference);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $entries[] = [
                'invoice_number' => $invoiceNumber,
                'supplier_reference' => $supplierReference,
            ];
        }

        return $entries;
    }
}
