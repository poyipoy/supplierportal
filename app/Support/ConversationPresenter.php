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
                'title' => $conversable->pr_number ?? __('purchasing.copy.draft_requisition'),
                'subtitle' => $conversable->period?->name ?? '-',
                'status' => strtoupper((string) $conversable->status),
                'url' => self::contextUrl($viewer, $conversation, $quotation),
                'fields' => array_values(array_filter([
                    ['label' => __('purchasing.copy.supplier'), 'value' => self::supplierName($conversation->supplierUser)],
                    ['label' => __('purchasing.copy.material'), 'value' => trans_choice('common.chat.material_count', $conversable->items->count())],
                    ['label' => __('purchasing.copy.quotation_status'), 'value' => $quotation?->statusLabel() ?? __('purchasing.copy.none')],
                    ['label' => __('purchasing.copy.currency'), 'value' => $quotation?->currency],
                    ['label' => __('purchasing.copy.total_quotation'), 'value' => $quotation ? self::quotationTotal($quotation) : null],
                    ['label' => __('purchasing.copy.estimated_delivery'), 'value' => self::formatDate($quotation?->estimated_delivery)],
                    ['label' => __('purchasing.copy.valid_until'), 'value' => self::formatDate($quotation?->validity_period)],
                ], fn ($field) => filled($field['value'] ?? null))),
                'quotation' => $quotation ? [
                    'id' => $quotation->id,
                    'status' => $quotation->status,
                    'status_label' => $quotation->statusLabel(),
                    'currency' => $quotation->currency,
                    'is_expired' => $quotation->isExpired(),
                    'show_url' => self::quotationUrl($viewer, $quotation),
                    'edit_url' => $viewer->role === 'supplier' && Route::has('supplier.quotations.create')
                        ? route('supplier.quotations.create', $quotation->purchaseRequisition)
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
                'title' => $conversable->po_number ?? __('purchasing.copy.purchase_order'),
                'subtitle' => $prNumbers ?: __('purchasing.copy.purchase_order'),
                'status' => strtoupper((string) $conversable->status),
                'url' => self::contextUrl($viewer, $conversation, null),
                'fields' => array_values(array_filter([
                    ['label' => __('purchasing.copy.supplier'), 'value' => self::supplierName($conversation->supplierUser)],
                    ['label' => __('purchasing.conversation.pr_number'), 'value' => $prNumbers],
                    ['label' => __('purchasing.copy.currency'), 'value' => $conversable->currency],
                    ['label' => __('purchasing.copy.estimated_arrival'), 'value' => self::formatDate($conversable->estimated_arrival)],
                    ['label' => __('purchasing.copy.actual_arrival'), 'value' => self::formatDate($conversable->actual_arrival)],
                ], fn ($field) => filled($field['value'] ?? null))),
                'quotation' => null,
            ];
        }

        return [
            'type' => 'DOC',
            'title' => $conversation->context_label,
            'subtitle' => __('purchasing.copy.chat_context'),
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
                'label' => __('purchasing.copy.open_revision_form'),
                'icon' => 'refresh-cw',
                'type' => 'link',
                'url' => route('supplier.quotations.create', $quotation->purchaseRequisition),
                'variant' => 'warning',
            ]];
        }

        if ($viewer->role !== 'purchasing') {
            return [];
        }

        $actions = [];

        if (in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ALL_UNAVAILABLE, Quotation::STATUS_REVISION_REQUESTED], true)) {
            if (in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ALL_UNAVAILABLE], true)) {
                $actions[] = [
                    'key' => 'request_price_revision',
                    'label' => __('purchasing.copy.request_revision'),
                    'icon' => 'badge-dollar-sign',
                    'type' => 'prompt',
                    'requires_note' => true,
                    'variant' => 'warning',
                ];
            }

            $actions = array_merge($actions, [
                [
                    'key' => 'request_validity_extension',
                    'label' => __('purchasing.copy.extend_validity'),
                    'icon' => 'calendar-plus',
                    'type' => 'prompt',
                    'requires_note' => false,
                    'variant' => 'outline-primary',
                ],
                [
                    'key' => 'request_delivery_confirmation',
                    'label' => __('purchasing.copy.confirm_estimated_delivery'),
                    'icon' => 'truck',
                    'type' => 'prompt',
                    'requires_note' => false,
                    'variant' => 'outline-primary',
                ],
            ]);
        }

        if ($quotation->canApproveBy($viewer) && ! $quotation->isExpired() && $quotation->hasAvailableItems()) {
            $actions[] = [
                'key' => 'accept_quotation',
                'label' => __('purchasing.copy.accept_quotation'),
                'icon' => 'circle-check',
                'type' => 'confirm',
                'requires_note' => false,
                'variant' => 'success',
            ];
        }

        if ($quotation->canApproveBy($viewer) && $quotation->hasAvailableItems()) {
            $actions[] = [
                'key' => 'reject_quotation',
                'label' => __('purchasing.copy.reject_quotation'),
                'icon' => 'circle-x',
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
                __('purchasing.copy.understood_we_will_review_the_price_and_supporting_documents_again'),
                __('purchasing.copy.we_will_send_the_revised_quotation_after_the_data_is_updated'),
                __('purchasing.copy.please_confirm_which_part_needs_to_be_revised_first'),
            ];
        }

        return [
            __('purchasing.copy.please_revise_the_price_for_the_submitted_material'),
            __('purchasing.copy.the_quotation_validity_needs_to_be_extended_before_po_processing'),
            __('purchasing.copy.please_confirm_the_latest_estimated_delivery_date'),
            __('purchasing.copy.please_attach_the_latest_supporting_quotation_documents'),
        ];
    }

    public static function slaMeta(Conversation $conversation, User $viewer): array
    {
        $latest = $conversation->latestMessage;

        if ($conversation->status === Conversation::STATUS_RESOLVED) {
            return [
                'label' => __('purchasing.copy.completed'),
                'class' => 'bg-success',
                'description' => $conversation->resolved_at
                    ? __('purchasing.conversation.resolved', ['time' => $conversation->resolved_at->copy()->locale(app()->getLocale())->diffForHumans()])
                    : __('purchasing.copy.the_conversation_is_completed'),
                'is_overdue' => false,
            ];
        }

        if (! $latest) {
            return [
                'label' => __('purchasing.copy.no_messages_yet'),
                'class' => 'bg-secondary',
                'description' => __('purchasing.copy.the_conversation_has_been_created_but_has_no_messages_yet'),
                'is_overdue' => false,
            ];
        }

        $needsViewerResponse = $latest->sender_id !== $viewer->id;
        $hours = $latest->created_at instanceof Carbon
            ? $latest->created_at->diffInHours(now())
            : 0;

        if ($needsViewerResponse && $hours >= 24) {
            return [
                'label' => __('purchasing.conversation.overdue'),
                'class' => 'bg-danger',
                'description' => __('purchasing.conversation.waiting_since', ['time' => $latest->created_at->copy()->locale(app()->getLocale())->diffForHumans()]),
                'is_overdue' => true,
            ];
        }

        if ($needsViewerResponse) {
            return [
                'label' => __('purchasing.copy.needs_reply'),
                'class' => 'bg-warning text-dark',
                'description' => __('purchasing.copy.the_latest_message_is_from_the_other_party'),
                'is_overdue' => false,
            ];
        }

        return [
            'label' => $conversation->statusLabelFor($viewer),
            'class' => $conversation->statusBadgeClassFor($viewer),
            'description' => __('purchasing.copy.the_latest_message_has_been_sent_and_is_waiting_for_the_other_party_response'),
            'is_overdue' => false,
        ];
    }

    private static function contextUrl(User $viewer, Conversation $conversation, ?Quotation $quotation): ?string
    {
        if ($conversation->conversable_type === PurchaseRequisition::class) {
            if ($viewer->role === 'purchasing' && Route::has('purchasing.requisitions.show')) {
                return route('purchasing.requisitions.show', $conversation->conversable);
            }

            if ($viewer->role === 'supplier' && $quotation && Route::has('supplier.quotations.show')) {
                return route('supplier.quotations.show', $quotation);
            }
        }

        if ($conversation->conversable_type === PurchaseOrder::class) {
            $route = $viewer->role === 'supplier'
                ? 'supplier.purchase-orders.show'
                : 'purchasing.purchase-orders.show';

            return Route::has($route) ? route($route, $conversation->conversable) : null;
        }

        return null;
    }

    private static function quotationUrl(User $viewer, Quotation $quotation): ?string
    {
        $route = $viewer->role === 'supplier'
            ? 'supplier.quotations.show'
            : 'purchasing.quotations.show';

        return Route::has($route) ? route($route, $quotation) : null;
    }

    private static function supplierName(?User $supplier): string
    {
        return $supplier?->supplier?->company_name ?? $supplier?->name ?? '-';
    }

    private static function quotationTotal(Quotation $quotation): string
    {
        $amount = $quotation->items->sum(fn ($item) => $item->resolved_amount);

        return number_format($amount, 2, ',', '.').' '.$quotation->currency;
    }

    private static function formatDate($date): ?string
    {
        if (! $date) {
            return null;
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $date->copy()->locale(app()->getLocale())->translatedFormat('d M Y');
    }
}
