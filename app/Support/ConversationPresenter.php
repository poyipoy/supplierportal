<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

class ConversationPresenter
{
    public static function relatedQuotation(Conversation $conversation): ?Quotation
    {
        if ($conversation->conversable_type !== PurchaseRequisition::class) {
            return null;
        }

        return Quotation::with([
                'exchange_rate',
                'items.prItem',
                'purchaseRequisition.period',
                'purchaseOrders',
            ])
            ->where('pr_id', $conversation->conversable_id)
            ->where('supplier_id', $conversation->supplier_user_id)
            ->latest('updated_at')
            ->first();
    }

    public static function context(Conversation $conversation, User $viewer): array
    {
        $conversation->loadMissing(['conversable', 'supplierUser.supplier', 'purchasingUser']);
        $quotation = self::relatedQuotation($conversation);
        $conversable = $conversation->conversable;

        if ($conversable instanceof PurchaseRequisition) {
            $conversable->loadMissing(['period', 'items']);

            return [
                'type' => 'PR',
                'title' => $conversable->pr_number ?? 'PR #' . $conversable->id,
                'subtitle' => $conversable->period?->name ?? '-',
                'status' => strtoupper((string) $conversable->status),
                'url' => self::contextUrl($viewer, $conversation, $quotation),
                'fields' => array_values(array_filter([
                    ['label' => 'Supplier', 'value' => self::supplierName($conversation->supplierUser)],
                    ['label' => 'Material', 'value' => $conversable->items->count() . ' item'],
                    ['label' => 'Quotation Status', 'value' => $quotation?->statusLabel() ?? 'None'],
                    ['label' => 'Currency', 'value' => $quotation?->currency],
                    ['label' => 'Total Quotation', 'value' => $quotation ? self::quotationTotal($quotation) : null],
                    ['label' => 'Estimated Delivery', 'value' => self::formatDate($quotation?->estimated_delivery)],
                    ['label' => 'Valid Until', 'value' => self::formatDate($quotation?->validity_period)],
                ], fn ($field) => filled($field['value'] ?? null))),
                'quotation' => $quotation ? [
                    'id' => $quotation->id,
                    'status' => $quotation->status,
                    'status_label' => $quotation->statusLabel(),
                    'currency' => $quotation->currency,
                    'is_expired' => $quotation->isExpired(),
                    'show_url' => self::quotationUrl($viewer, $quotation),
                    'edit_url' => $viewer->role === 'supplier' && Route::has('supplier.quotations.create')
                        ? route('supplier.quotations.create', $quotation->pr_id)
                        : null,
                ] : null,
            ];
        }

        if ($conversable instanceof PurchaseOrder) {
            $conversable->loadMissing(['supplier.supplier', 'quotations.purchaseRequisition']);
            $prNumbers = $conversable->purchaseRequisitions()
                ->pluck('pr_number')
                ->filter()
                ->implode(', ');

            return [
                'type' => 'PO',
                'title' => $conversable->po_number ?? 'PO #' . $conversable->id,
                'subtitle' => $prNumbers ?: 'Purchase Order',
                'status' => strtoupper((string) $conversable->status),
                'url' => self::contextUrl($viewer, $conversation, null),
                'fields' => array_values(array_filter([
                    ['label' => 'Supplier', 'value' => self::supplierName($conversation->supplierUser)],
                    ['label' => 'No. PR', 'value' => $prNumbers],
                    ['label' => 'Currency', 'value' => $conversable->currency],
                    ['label' => 'Estimated Arrival', 'value' => self::formatDate($conversable->estimated_arrival)],
                    ['label' => 'Actual Arrival', 'value' => self::formatDate($conversable->actual_arrival)],
                ], fn ($field) => filled($field['value'] ?? null))),
                'quotation' => null,
            ];
        }

        return [
            'type' => 'DOC',
            'title' => $conversation->context_label,
            'subtitle' => 'Chat context',
            'status' => null,
            'url' => null,
            'fields' => [],
            'quotation' => null,
        ];
    }

    public static function quickActions(Conversation $conversation, User $viewer): array
    {
        $quotation = self::relatedQuotation($conversation);

        if (! $quotation) {
            return [];
        }

        if ($viewer->role === 'supplier' && $quotation->status === Quotation::STATUS_REVISION_REQUESTED) {
            return [[
                'key' => 'open_revision',
                'label' => 'Open Revision Form',
                'icon' => 'bi-arrow-repeat',
                'type' => 'link',
                'url' => route('supplier.quotations.create', $quotation->pr_id),
                'variant' => 'warning',
            ]];
        }

        if ($viewer->role !== 'purchasing') {
            return [];
        }

        $actions = [];

        if (in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_REVISION_REQUESTED], true)) {
            if ($quotation->status === Quotation::STATUS_SUBMITTED) {
                $actions[] = [
                    'key' => 'request_price_revision',
                    'label' => 'Request Price Revision',
                    'icon' => 'bi-cash-coin',
                    'type' => 'prompt',
                    'requires_note' => true,
                    'variant' => 'warning',
                ];
            }

            $actions = array_merge($actions, [
                [
                    'key' => 'request_validity_extension',
                    'label' => 'Extend Validity',
                    'icon' => 'bi-calendar2-plus',
                    'type' => 'prompt',
                    'requires_note' => false,
                    'variant' => 'outline-primary',
                ],
                [
                    'key' => 'request_delivery_confirmation',
                    'label' => 'Confirm Estimated Delivery',
                    'icon' => 'bi-truck',
                    'type' => 'prompt',
                    'requires_note' => false,
                    'variant' => 'outline-primary',
                ],
            ]);
        }

        if ($quotation->canApproveBy($viewer) && ! $quotation->isExpired()) {
            $actions[] = [
                'key' => 'accept_quotation',
                'label' => 'Accept Quotation',
                'icon' => 'bi-check2-circle',
                'type' => 'confirm',
                'requires_note' => false,
                'variant' => 'success',
            ];
        }

        if ($quotation->canApproveBy($viewer)) {
            $actions[] = [
                'key' => 'reject_quotation',
                'label' => 'Reject Quotation',
                'icon' => 'bi-x-circle',
                'type' => 'prompt',
                'requires_note' => true,
                'variant' => 'outline-danger',
            ];
        }

        return $actions;
    }

    public static function templates(Conversation $conversation, User $viewer): array
    {
        if ($viewer->role === 'supplier') {
            return [
                'Understood, we will review the price and supporting documents again.',
                'We will send the revised quotation after the data is updated.',
                'Please confirm which part needs to be revised first.',
            ];
        }

        return [
            'Please revise the price for the submitted material.',
            'The quotation validity needs to be extended before PO processing.',
            'Please confirm the latest estimated delivery date.',
            'Please attach the latest supporting quotation documents.',
        ];
    }

    public static function slaMeta(Conversation $conversation, User $viewer): array
    {
        $latest = $conversation->latestMessage;

        if ($conversation->status === Conversation::STATUS_RESOLVED) {
            return [
                'label' => 'Completed',
                'class' => 'bg-success',
                'description' => $conversation->resolved_at
                    ? 'Resolved ' . $conversation->resolved_at->diffForHumans()
                    : 'The conversation is completed.',
                'is_overdue' => false,
            ];
        }

        if (! $latest) {
            return [
                'label' => 'No messages yet',
                'class' => 'bg-secondary',
                'description' => 'The conversation has been created but has no messages yet.',
                'is_overdue' => false,
            ];
        }

        $needsViewerResponse = $latest->sender_id !== $viewer->id;
        $hours = $latest->created_at instanceof Carbon
            ? $latest->created_at->diffInHours(now())
            : 0;

        if ($needsViewerResponse && $hours >= 24) {
            return [
                'label' => 'No reply for > 1 day',
                'class' => 'bg-danger',
                'description' => 'The latest message has been waiting for your reply since ' . $latest->created_at->diffForHumans() . '.',
                'is_overdue' => true,
            ];
        }

        if ($needsViewerResponse) {
            return [
                'label' => 'Needs Reply',
                'class' => 'bg-warning text-dark',
                'description' => 'The latest message is from the other party.',
                'is_overdue' => false,
            ];
        }

        return [
            'label' => $conversation->statusLabelFor($viewer),
            'class' => $conversation->statusBadgeClassFor($viewer),
            'description' => 'The latest message has been sent and is waiting for the other party response.',
            'is_overdue' => false,
        ];
    }

    private static function contextUrl(User $viewer, Conversation $conversation, ?Quotation $quotation): ?string
    {
        if ($conversation->conversable_type === PurchaseRequisition::class) {
            if ($viewer->role === 'purchasing' && Route::has('purchasing.requisitions.show')) {
                return route('purchasing.requisitions.show', $conversation->conversable_id);
            }

            if ($viewer->role === 'supplier' && $quotation && Route::has('supplier.quotations.show')) {
                return route('supplier.quotations.show', $quotation->id);
            }
        }

        if ($conversation->conversable_type === PurchaseOrder::class) {
            $route = $viewer->role === 'supplier'
                ? 'supplier.purchase-orders.show'
                : 'purchasing.purchase-orders.show';

            return Route::has($route) ? route($route, $conversation->conversable_id) : null;
        }

        return null;
    }

    private static function quotationUrl(User $viewer, Quotation $quotation): ?string
    {
        $route = $viewer->role === 'supplier'
            ? 'supplier.quotations.show'
            : 'purchasing.quotations.show';

        return Route::has($route) ? route($route, $quotation->id) : null;
    }

    private static function supplierName(?User $supplier): string
    {
        return $supplier?->supplier?->company_name ?? $supplier?->name ?? '-';
    }

    private static function quotationTotal(Quotation $quotation): string
    {
        $amount = $quotation->items->sum(fn ($item) => (float) $item->amount);

        return number_format($amount, 2, ',', '.') . ' ' . $quotation->currency;
    }

    private static function formatDate($date): ?string
    {
        if (! $date) {
            return null;
        }

        return $date instanceof Carbon
            ? $date->format('d M Y')
            : Carbon::parse($date)->format('d M Y');
    }
}
