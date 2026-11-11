<?php

namespace App\Services\DocumentChain;

use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Support\HashidCodec;
use App\Support\StatusHelper;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocumentChainResolverService
{
    /**
     * Resolve the contextual document chain, previewable PDFs, and SLA summary
     * while enforcing strict role, domain, policy, and supplier ownership boundaries.
     *
     * @return array{
     *     entity_type: string,
     *     title: string,
     *     subtitle: string,
     *     status: string,
     *     status_tone: string,
     *     nodes: list<array{type: string, label: string, reference: string, status: string, tone: string, date: ?string, url: ?string, current: bool}>,
     *     documents: list<array{label: string, filename: string, url: string}>,
     *     sla_summary: list<array{label: string, value: string, tone: string}>
     * }
     */
    public function resolve(User $actor, string $entityType, int|string $idOrHash): array
    {
        $id = $this->resolveId($idOrHash);

        return match ($entityType) {
            'import-pr' => $this->resolveImportPr($actor, $id),
            'import-po' => $this->resolveImportPo($actor, $id),
            'local-po' => $this->resolveLocalPo($actor, $id),
            'local-invoice' => $this->resolveLocalInvoice($actor, $id),
            'payment-batch' => $this->resolvePaymentBatch($actor, $id),
            default => throw new NotFoundHttpException,
        };
    }

    private function resolveId(int|string $idOrHash): int
    {
        if (is_int($idOrHash)) {
            return $idOrHash;
        }

        if (ctype_digit($idOrHash)) {
            return (int) $idOrHash;
        }

        $decoded = HashidCodec::decode($idOrHash);
        if ($decoded === null) {
            throw new NotFoundHttpException;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveImportPr(User $actor, int $id): array
    {
        if ($actor->isSupplier()) {
            if (! $actor->hasSupplierScope('import')) {
                throw new AccessDeniedHttpException;
            }

            $pr = PurchaseRequisition::query()
                ->visibleToSupplier($actor->id)
                ->whereKey($id)
                ->first();

            if (! $pr) {
                throw new AccessDeniedHttpException;
            }
        } elseif (in_array($actor->role, ['admin', 'purchasing'], true)) {
            $pr = PurchaseRequisition::query()->findOrFail($id);
        } else {
            throw new AccessDeniedHttpException;
        }

        $nodes = [
            [
                'type' => 'PR',
                'label' => __('smart_panel.node_types.pr'),
                'reference' => $pr->pr_number,
                'status' => strtoupper((string) $pr->status),
                'tone' => StatusHelper::prTone((string) $pr->status),
                'date' => $pr->created_at?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('supplier.requests.show', $pr, false)
                    : route('purchasing.requisitions.show', $pr, false),
                'current' => true,
            ],
        ];

        $quotationsQuery = $pr->quotations()->orderBy('id');
        if ($actor->isSupplier()) {
            $quotationsQuery->where('supplier_id', $actor->id);
        }

        $quotations = $quotationsQuery->get();
        foreach ($quotations as $quotation) {
            $nodes[] = [
                'type' => 'QUOTATION',
                'label' => __('smart_panel.node_types.quotation'),
                'reference' => 'QUO-'.$quotation->hashid,
                'status' => strtoupper((string) $quotation->status),
                'tone' => StatusHelper::quotationTone((string) $quotation->status),
                'date' => $quotation->submitted_at?->format('Y-m-d') ?? $quotation->created_at?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('supplier.quotations.show', $quotation, false)
                    : route('purchasing.quotations.show', $quotation, false),
                'current' => false,
            ];
        }

        $poIds = $quotations->isEmpty()
            ? []
            : PurchaseOrder::query()
                ->when($actor->isSupplier(), fn ($q) => $q->where('supplier_id', $actor->id))
                ->whereHas('quotations', fn ($q) => $q->whereIn('quotations.id', $quotations->pluck('id')))
                ->pluck('id')
                ->all();

        $pos = empty($poIds)
            ? collect()
            : PurchaseOrder::query()
                ->when($actor->isSupplier(), fn ($q) => $q->where('supplier_id', $actor->id))
                ->whereIn('id', $poIds)
                ->orderBy('id')
                ->get();

        foreach ($pos as $po) {
            $nodes[] = [
                'type' => 'PO',
                'label' => __('smart_panel.node_types.po'),
                'reference' => $po->po_number,
                'status' => strtoupper((string) $po->status),
                'tone' => StatusHelper::poTone((string) $po->status),
                'date' => $po->created_at?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('supplier.purchase-orders.show', $po, false)
                    : route('purchasing.purchase-orders.show', $po, false),
                'current' => false,
            ];
        }

        $slaSummary = [
            [
                'label' => __('smart_panel.sla.created_date'),
                'value' => $pr->created_at?->format('Y-m-d') ?? '—',
                'tone' => 'neutral',
            ],
            [
                'label' => __('smart_panel.sla.quotations_count'),
                'value' => (string) $quotations->count(),
                'tone' => $quotations->isNotEmpty() ? 'info' : 'warning',
            ],
        ];

        return [
            'entity_type' => 'import-pr',
            'title' => $pr->pr_number,
            'subtitle' => __('smart_panel.node_types.pr'),
            'status' => strtoupper((string) $pr->status),
            'status_tone' => StatusHelper::prTone((string) $pr->status),
            'nodes' => $nodes,
            'documents' => [],
            'sla_summary' => $slaSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveImportPo(User $actor, int $id): array
    {
        if ($actor->isSupplier()) {
            if (! $actor->hasSupplierScope('import')) {
                throw new AccessDeniedHttpException;
            }

            $po = PurchaseOrder::query()
                ->where('supplier_id', $actor->id)
                ->whereKey($id)
                ->first();

            if (! $po) {
                throw new AccessDeniedHttpException;
            }
        } else {
            $po = PurchaseOrder::query()->findOrFail($id);
        }

        Gate::forUser($actor)->authorize('view', $po);

        $po->loadMissing([
            'quotations.purchaseRequisition',
            'shipmentItems.shipment',
            'qcInspections',
            'materialClaims',
            'attachments',
        ]);

        $nodes = [];
        $seenPrIds = [];

        foreach ($po->quotations as $quotation) {
            if ($actor->isSupplier() && (int) $quotation->supplier_id !== (int) $actor->id) {
                continue;
            }

            $pr = $quotation->purchaseRequisition;
            if ($pr && ! in_array($pr->id, $seenPrIds, true)) {
                $seenPrIds[] = $pr->id;
                $nodes[] = [
                    'type' => 'PR',
                    'label' => __('smart_panel.node_types.pr'),
                    'reference' => $pr->pr_number,
                    'status' => strtoupper((string) $pr->status),
                    'tone' => StatusHelper::prTone((string) $pr->status),
                    'date' => $pr->created_at?->format('Y-m-d'),
                    'url' => $actor->isSupplier()
                        ? route('supplier.requests.show', $pr, false)
                        : ($actor->role === 'qc' ? null : route('purchasing.requisitions.show', $pr, false)),
                    'current' => false,
                ];
            }

            $nodes[] = [
                'type' => 'QUOTATION',
                'label' => __('smart_panel.node_types.quotation'),
                'reference' => 'QUO-'.$quotation->hashid,
                'status' => strtoupper((string) $quotation->status),
                'tone' => StatusHelper::quotationTone((string) $quotation->status),
                'date' => $quotation->submitted_at?->format('Y-m-d') ?? $quotation->created_at?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('supplier.quotations.show', $quotation, false)
                    : ($actor->role === 'qc' ? null : route('purchasing.quotations.show', $quotation, false)),
                'current' => false,
            ];
        }

        $nodes[] = [
            'type' => 'PO',
            'label' => __('smart_panel.node_types.po'),
            'reference' => $po->po_number,
            'status' => strtoupper((string) $po->status),
            'tone' => StatusHelper::poTone((string) $po->status),
            'date' => $po->created_at?->format('Y-m-d'),
            'url' => $actor->isSupplier()
                ? route('supplier.purchase-orders.show', $po, false)
                : ($actor->role === 'qc' ? null : route('purchasing.purchase-orders.show', $po, false)),
            'current' => true,
        ];

        foreach ($po->shipments() as $shipment) {
            if ($actor->isSupplier() && (int) $shipment->supplier_id !== (int) $actor->id) {
                continue;
            }

            $nodes[] = [
                'type' => 'SHIPMENT',
                'label' => __('smart_panel.node_types.shipment'),
                'reference' => $shipment->shipment_number,
                'status' => strtoupper((string) $shipment->status),
                'tone' => StatusHelper::shipmentTone((string) $shipment->status),
                'date' => $shipment->shipped_at?->format('Y-m-d') ?? $shipment->created_at?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('supplier.shipments.show', $shipment, false)
                    : ($actor->role === 'qc' ? null : route('purchasing.shipments.show', $shipment, false)),
                'current' => false,
            ];
        }

        foreach ($po->qcInspections as $inspection) {
            $nodes[] = [
                'type' => 'QC',
                'label' => __('smart_panel.node_types.qc'),
                'reference' => 'QC-'.$inspection->hashid,
                'status' => strtoupper((string) $inspection->status),
                'tone' => $inspection->status === 'ok' ? 'success' : 'danger',
                'date' => $inspection->inspected_at?->format('Y-m-d'),
                'url' => $actor->role === 'qc' ? route('qc.inspections.show', $inspection, false) : null,
                'current' => false,
            ];
        }

        foreach ($po->materialClaims as $claim) {
            if ($actor->isSupplier() && (int) $claim->supplier_id !== (int) $actor->id) {
                continue;
            }

            $nodes[] = [
                'type' => 'CLAIM',
                'label' => __('smart_panel.node_types.claim'),
                'reference' => 'CLM-'.$claim->hashid,
                'status' => strtoupper((string) $claim->status),
                'tone' => StatusHelper::claimTone((string) $claim->status),
                'date' => $claim->created_at?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('supplier.claims.show', $claim, false)
                    : ($actor->role === 'qc' ? null : route('purchasing.claims.show', $claim, false)),
                'current' => false,
            ];
        }

        $documents = [
            [
                'label' => __('smart_panel.documents.po_pdf'),
                'filename' => $po->po_number.'.pdf',
                'url' => route('shared.pdf.purchase-order', ['id' => $po, 'inline' => 1], false),
                'download_url' => route('shared.pdf.purchase-order', $po, false),
            ],
        ];

        foreach ($po->attachments as $attachment) {
            $documents[] = [
                'label' => __('smart_panel.documents.po_attachment'),
                'filename' => $attachment->file_name,
                'url' => route('attachments.show', $attachment, false),
                'download_url' => route('attachments.show', ['attachment' => $attachment, 'download' => 1], false),
            ];
        }

        $ackEnum = $po->acknowledgementStatusEnum();
        $slaSummary = [
            [
                'label' => __('purchasing.po_ack.title'),
                'value' => $ackEnum->label(),
                'tone' => $ackEnum->tone(),
            ],
            [
                'label' => __('purchasing.po_ack.target_delivery_date'),
                'value' => $po->estimated_arrival?->format('Y-m-d') ?? '—',
                'tone' => 'neutral',
            ],
            [
                'label' => __('purchasing.po_ack.promised_date'),
                'value' => $po->supplier_promised_date?->format('Y-m-d') ?? '—',
                'tone' => 'info',
            ],
        ];

        return [
            'entity_type' => 'import-po',
            'title' => $po->po_number,
            'subtitle' => __('smart_panel.node_types.po'),
            'status' => strtoupper((string) $po->status),
            'status_tone' => StatusHelper::poTone((string) $po->status),
            'nodes' => $nodes,
            'documents' => $documents,
            'sla_summary' => $slaSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLocalPo(User $actor, int $id): array
    {
        if ($actor->isSupplier()) {
            if (! $actor->hasSupplierScope('local')) {
                throw new AccessDeniedHttpException;
            }

            $localPo = LocalPurchaseOrder::query()
                ->where('supplier_id', $actor->id)
                ->whereKey($id)
                ->first();

            if (! $localPo) {
                throw new AccessDeniedHttpException;
            }
        } else {
            $localPo = LocalPurchaseOrder::query()->findOrFail($id);
        }

        Gate::forUser($actor)->authorize('view', $localPo);

        $localPo->loadMissing(['goodsReceipts', 'invoices', 'latestPoAttachment']);

        $nodes = [
            [
                'type' => 'LOCAL_PO',
                'label' => __('smart_panel.node_types.local_po'),
                'reference' => $localPo->po_number,
                'status' => (string) $localPo->status,
                'tone' => StatusHelper::localFinanceTone((string) $localPo->status),
                'date' => $localPo->po_date?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('local-supplier.purchase-orders.show', $localPo, false)
                    : ($actor->role === 'purchasing'
                        ? route('purchasing.local-procurement.show', $localPo, false)
                        : (in_array($actor->role, ['finance', 'admin'], true) ? route('finance.local-procurement.show', $localPo, false) : null)),
                'current' => true,
            ],
        ];

        foreach ($localPo->goodsReceipts as $gr) {
            $nodes[] = [
                'type' => 'GR',
                'label' => __('smart_panel.node_types.gr'),
                'reference' => $gr->gr_number,
                'status' => (string) $gr->status,
                'tone' => StatusHelper::localFinanceTone((string) $gr->status),
                'date' => $gr->gr_date?->format('Y-m-d'),
                'url' => null,
                'current' => false,
            ];
        }

        $invoices = $actor->isSupplier()
            ? $localPo->invoices->where('supplier_id', $actor->id)
            : $localPo->invoices;

        foreach ($invoices as $invoice) {
            $nodes[] = [
                'type' => 'INVOICE',
                'label' => __('smart_panel.node_types.local_invoice'),
                'reference' => $invoice->invoice_number,
                'status' => StatusHelper::localInvoiceLabel((string) $invoice->status),
                'tone' => StatusHelper::localInvoiceTone((string) $invoice->status),
                'date' => $invoice->invoice_date?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('local-supplier.invoices.show', $invoice, false)
                    : ($actor->role === 'purchasing'
                        ? route('purchasing.local-invoices.show', $invoice, false)
                        : route('finance.invoices.show', $invoice, false)),
                'current' => false,
            ];
        }

        $documents = [];
        if ($localPo->latestPoAttachment) {
            $documents[] = [
                'label' => __('smart_panel.documents.local_po_pdf'),
                'filename' => $localPo->latestPoAttachment->file_name,
                'url' => route('attachments.show', $localPo->latestPoAttachment, false),
                'download_url' => route('attachments.show', ['attachment' => $localPo->latestPoAttachment, 'download' => 1], false),
            ];
        }

        return [
            'entity_type' => 'local-po',
            'title' => $localPo->po_number,
            'subtitle' => __('smart_panel.node_types.local_po'),
            'status' => (string) $localPo->status,
            'status_tone' => StatusHelper::localFinanceTone((string) $localPo->status),
            'nodes' => $nodes,
            'documents' => $documents,
            'sla_summary' => [
                [
                    'label' => __('local_procurement.labels.po_date'),
                    'value' => $localPo->po_date?->format('Y-m-d') ?? '—',
                    'tone' => 'neutral',
                ],
                [
                    'label' => __('local_procurement.labels.gr_total'),
                    'value' => (string) $localPo->goodsReceipts->count(),
                    'tone' => $localPo->goodsReceipts->isNotEmpty() ? 'info' : 'neutral',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLocalInvoice(User $actor, int $id): array
    {
        if ($actor->isSupplier()) {
            if (! $actor->hasSupplierScope('local')) {
                throw new AccessDeniedHttpException;
            }

            $invoice = LocalInvoice::query()
                ->where('supplier_id', $actor->id)
                ->whereKey($id)
                ->first();

            if (! $invoice) {
                throw new AccessDeniedHttpException;
            }
        } else {
            $invoice = LocalInvoice::query()->findOrFail($id);
        }

        Gate::forUser($actor)->authorize('view', $invoice);

        $invoice->loadMissing([
            'localPurchaseOrder',
            'goodsReceiptHistories.goodsReceipt',
            'revisions.documents',
            'paymentItem.group.batch',
            'payment',
        ]);

        $nodes = [];
        if ($invoice->localPurchaseOrder) {
            $po = $invoice->localPurchaseOrder;
            $nodes[] = [
                'type' => 'LOCAL_PO',
                'label' => __('smart_panel.node_types.local_po'),
                'reference' => $po->po_number,
                'status' => (string) $po->status,
                'tone' => StatusHelper::localFinanceTone((string) $po->status),
                'date' => $po->po_date?->format('Y-m-d'),
                'url' => $actor->isSupplier()
                    ? route('local-supplier.purchase-orders.show', $po, false)
                    : ($actor->role === 'purchasing'
                        ? route('purchasing.local-procurement.show', $po, false)
                        : (in_array($actor->role, ['finance', 'admin'], true) ? route('finance.local-procurement.show', $po, false) : null)),
                'current' => false,
            ];
        }

        foreach ($invoice->goodsReceiptHistories->sortBy('id') as $history) {
            $nodes[] = [
                'type' => 'GR',
                'label' => __('smart_panel.node_types.gr'),
                'reference' => $history->gr_number_snapshot,
                'status' => StatusHelper::localFinanceLabel((string) $history->state),
                'tone' => StatusHelper::localFinanceTone((string) $history->state),
                'date' => $history->goodsReceipt?->gr_date?->format('Y-m-d'),
                'url' => null,
                'current' => false,
            ];
        }

        $nodes[] = [
            'type' => 'INVOICE',
            'label' => __('smart_panel.node_types.local_invoice'),
            'reference' => $invoice->invoice_number,
            'status' => StatusHelper::localInvoiceLabel((string) $invoice->status),
            'tone' => StatusHelper::localInvoiceTone((string) $invoice->status),
            'date' => $invoice->invoice_date?->format('Y-m-d'),
            'url' => $actor->isSupplier()
                ? route('local-supplier.invoices.show', $invoice, false)
                : ($actor->role === 'purchasing'
                    ? route('purchasing.local-invoices.show', $invoice, false)
                    : route('finance.invoices.show', $invoice, false)),
            'current' => true,
        ];

        $batch = $invoice->paymentItem?->group?->batch;
        if (! $actor->isSupplier() && $batch) {
            $nodes[] = [
                'type' => 'DRP',
                'label' => __('smart_panel.node_types.drp'),
                'reference' => $batch->batch_number,
                'status' => StatusHelper::localFinanceLabel((string) $batch->status),
                'tone' => StatusHelper::localFinanceTone((string) $batch->status),
                'date' => ($batch->finalized_at ?? $batch->created_at)?->format('Y-m-d'),
                'url' => in_array($actor->role, ['finance', 'admin'], true)
                    ? route('finance.drp.show', $batch, false)
                    : null,
                'current' => false,
            ];
        }

        $payment = $invoice->payment;
        if ($payment) {
            $nodes[] = [
                'type' => 'PAYMENT',
                'label' => __('smart_panel.node_types.payment'),
                'reference' => 'PAY-'.$payment->hashid,
                'status' => StatusHelper::localFinanceLabel((string) $payment->status),
                'tone' => StatusHelper::localFinanceTone((string) $payment->status),
                'date' => $payment->finalized_at?->format('Y-m-d'),
                'url' => null,
                'current' => false,
            ];
        }

        $documents = [];
        $latestRevision = $invoice->revisions->last();
        if ($latestRevision) {
            foreach ($latestRevision->documents as $doc) {
                $documents[] = [
                    'label' => strtoupper((string) $doc->document_type),
                    'filename' => $doc->original_filename,
                    'url' => route('local-invoice-documents.show', ['document' => $doc, 'inline' => 1], false),
                    'download_url' => route('local-invoice-documents.show', $doc, false),
                ];
            }
        }

        $slaSummary = [
            [
                'label' => __('local_invoice.three_way_match.title'),
                'value' => StatusHelper::threeWayMatchLabel($invoice->three_way_match_status),
                'tone' => StatusHelper::threeWayMatchTone($invoice->three_way_match_status),
            ],
            [
                'label' => __('smart_panel.sla.scheduled_delivery'),
                'value' => $invoice->scheduled_physical_delivery_date?->format('Y-m-d') ?? '—',
                'tone' => 'neutral',
            ],
            [
                'label' => __('smart_panel.sla.due_date'),
                'value' => $invoice->due_date?->format('Y-m-d') ?? '—',
                'tone' => 'info',
            ],
        ];

        return [
            'entity_type' => 'local-invoice',
            'title' => $invoice->invoice_number,
            'subtitle' => __('smart_panel.node_types.local_invoice'),
            'status' => StatusHelper::localInvoiceLabel((string) $invoice->status),
            'status_tone' => StatusHelper::localInvoiceTone((string) $invoice->status),
            'nodes' => $nodes,
            'documents' => $documents,
            'sla_summary' => $slaSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePaymentBatch(User $actor, int $id): array
    {
        if (! in_array($actor->role, ['admin', 'finance', 'accounting', 'purchasing'], true)) {
            throw new AccessDeniedHttpException;
        }

        $batch = PaymentBatch::query()
            ->with(['groups.items.payable', 'groups.items.localInvoiceVoucher'])
            ->findOrFail($id);

        $nodes = [
            [
                'type' => 'DRP',
                'label' => __('smart_panel.node_types.drp'),
                'reference' => $batch->batch_number,
                'status' => StatusHelper::localFinanceLabel((string) $batch->status),
                'tone' => StatusHelper::localFinanceTone((string) $batch->status),
                'date' => ($batch->finalized_at ?? $batch->created_at)?->format('Y-m-d'),
                'url' => in_array($actor->role, ['finance', 'admin'], true)
                    ? route('finance.drp.show', $batch, false)
                    : null,
                'current' => true,
            ],
        ];

        foreach ($batch->groups as $group) {
            /** @var PaymentItem $item */
            foreach ($group->items as $item) {
                $voucher = $item->localInvoiceVoucher;
                if ($voucher) {
                    $nodes[] = [
                        'type' => 'VOUCHER',
                        'label' => __('smart_panel.node_types.voucher'),
                        'reference' => $voucher->voucher_number,
                        'status' => StatusHelper::localFinanceLabel((string) $voucher->status),
                        'tone' => StatusHelper::localFinanceTone((string) $voucher->status),
                        'date' => $voucher->voucher_date?->format('Y-m-d'),
                        'url' => in_array($actor->role, ['finance', 'admin'], true)
                            ? route('finance.vouchers.show', $voucher, false)
                            : null,
                        'current' => false,
                    ];
                }

                $payable = $item->payable;
                if ($payable instanceof LocalInvoice) {
                    $nodes[] = [
                        'type' => 'INVOICE',
                        'label' => __('smart_panel.node_types.local_invoice'),
                        'reference' => $payable->invoice_number,
                        'status' => StatusHelper::localInvoiceLabel((string) $payable->status),
                        'tone' => StatusHelper::localInvoiceTone((string) $payable->status),
                        'date' => $payable->invoice_date?->format('Y-m-d'),
                        'url' => in_array($actor->role, ['finance', 'admin'], true)
                            ? route('finance.invoices.show', $payable, false)
                            : route('purchasing.local-invoices.show', $payable, false),
                        'current' => false,
                    ];
                }
            }
        }

        return [
            'entity_type' => 'payment-batch',
            'title' => $batch->batch_number,
            'subtitle' => __('smart_panel.node_types.drp'),
            'status' => StatusHelper::localFinanceLabel((string) $batch->status),
            'status_tone' => StatusHelper::localFinanceTone((string) $batch->status),
            'nodes' => $nodes,
            'documents' => [],
            'sla_summary' => [
                [
                    'label' => __('smart_panel.sla.finalized_date'),
                    'value' => $batch->finalized_at?->format('Y-m-d') ?? '—',
                    'tone' => 'info',
                ],
                [
                    'label' => __('smart_panel.sla.groups_count'),
                    'value' => (string) $batch->groups->count(),
                    'tone' => 'neutral',
                ],
            ],
        ];
    }
}
