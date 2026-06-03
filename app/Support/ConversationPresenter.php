<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

class ConversationPresenter
{
    public static function relatedQuotation(Conversation $conversation): ?Quotation
    {
        if ($conversation->conversable_type !== PurchaseRequirement::class) {
            return null;
        }

        return Quotation::with([
                'exchange_rate',
                'items.prItem',
                'purchaseRequirement.period',
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

        if ($conversable instanceof PurchaseRequirement) {
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
                    ['label' => 'Status Penawaran', 'value' => $quotation?->statusLabel() ?? 'Belum ada'],
                    ['label' => 'Mata Uang', 'value' => $quotation?->currency],
                    ['label' => 'Total Penawaran', 'value' => $quotation ? self::quotationTotal($quotation) : null],
                    ['label' => 'Estimasi Kirim', 'value' => self::formatDate($quotation?->estimated_delivery)],
                    ['label' => 'Masa Berlaku', 'value' => self::formatDate($quotation?->validity_period)],
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
            $conversable->loadMissing(['supplier.supplier', 'quotations.purchaseRequirement']);
            $prNumbers = $conversable->purchaseRequirements()
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
                    ['label' => 'Mata Uang', 'value' => $conversable->currency],
                    ['label' => 'Estimasi Tiba', 'value' => self::formatDate($conversable->estimated_arrival)],
                    ['label' => 'Aktual Tiba', 'value' => self::formatDate($conversable->actual_arrival)],
                ], fn ($field) => filled($field['value'] ?? null))),
                'quotation' => null,
            ];
        }

        return [
            'type' => 'DOC',
            'title' => $conversation->context_label,
            'subtitle' => 'Konteks chat',
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
                'label' => 'Buka Form Revisi',
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
                    'label' => 'Minta Revisi Harga',
                    'icon' => 'bi-cash-coin',
                    'type' => 'prompt',
                    'requires_note' => true,
                    'variant' => 'warning',
                ];
            }

            $actions = array_merge($actions, [
                [
                    'key' => 'request_validity_extension',
                    'label' => 'Perpanjang Masa Berlaku',
                    'icon' => 'bi-calendar2-plus',
                    'type' => 'prompt',
                    'requires_note' => false,
                    'variant' => 'outline-primary',
                ],
                [
                    'key' => 'request_delivery_confirmation',
                    'label' => 'Konfirmasi Estimasi Kirim',
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
                'label' => 'Terima Penawaran',
                'icon' => 'bi-check2-circle',
                'type' => 'confirm',
                'requires_note' => false,
                'variant' => 'success',
            ];
        }

        if ($quotation->canApproveBy($viewer)) {
            $actions[] = [
                'key' => 'reject_quotation',
                'label' => 'Tolak Penawaran',
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
                'Baik, kami akan cek kembali harga dan dokumen pendukungnya.',
                'Kami akan kirim revisi penawaran setelah data diperbarui.',
                'Mohon konfirmasi bagian mana yang perlu kami revisi terlebih dahulu.',
            ];
        }

        return [
            'Mohon revisi harga untuk material yang sudah diajukan.',
            'Masa berlaku penawaran perlu diperpanjang sebelum proses PO.',
            'Mohon konfirmasi estimasi pengiriman terbaru.',
            'Mohon lampirkan dokumen pendukung penawaran terbaru.',
        ];
    }

    public static function slaMeta(Conversation $conversation, User $viewer): array
    {
        $latest = $conversation->latestMessage;

        if ($conversation->status === Conversation::STATUS_RESOLVED) {
            return [
                'label' => 'Selesai',
                'class' => 'bg-success',
                'description' => $conversation->resolved_at
                    ? 'Diselesaikan ' . $conversation->resolved_at->diffForHumans()
                    : 'Percakapan sudah selesai.',
                'is_overdue' => false,
            ];
        }

        if (! $latest) {
            return [
                'label' => 'Belum ada pesan',
                'class' => 'bg-secondary',
                'description' => 'Percakapan sudah dibuat tetapi belum ada pesan.',
                'is_overdue' => false,
            ];
        }

        $needsViewerResponse = $latest->sender_id !== $viewer->id;
        $hours = $latest->created_at instanceof Carbon
            ? $latest->created_at->diffInHours(now())
            : 0;

        if ($needsViewerResponse && $hours >= 24) {
            return [
                'label' => 'Belum dibalas > 1 hari',
                'class' => 'bg-danger',
                'description' => 'Pesan terakhir menunggu balasan Anda sejak ' . $latest->created_at->diffForHumans() . '.',
                'is_overdue' => true,
            ];
        }

        if ($needsViewerResponse) {
            return [
                'label' => 'Perlu dibalas',
                'class' => 'bg-warning text-dark',
                'description' => 'Pesan terakhir berasal dari lawan bicara.',
                'is_overdue' => false,
            ];
        }

        return [
            'label' => $conversation->statusLabelFor($viewer),
            'class' => $conversation->statusBadgeClassFor($viewer),
            'description' => 'Pesan terakhir sudah dikirim dan menunggu respons lawan bicara.',
            'is_overdue' => false,
        ];
    }

    private static function contextUrl(User $viewer, Conversation $conversation, ?Quotation $quotation): ?string
    {
        if ($conversation->conversable_type === PurchaseRequirement::class) {
            if ($viewer->role === 'purchasing' && Route::has('purchasing.requirements.show')) {
                return route('purchasing.requirements.show', $conversation->conversable_id);
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
