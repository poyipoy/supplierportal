<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseRequisitionStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionCancellationService
{
    public function __construct(
        private readonly PurchaseRequisitionStateEngine $stateEngine,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Terminally cancel a submitted Purchase Requisition that has zero quotations.
     *
     * @throws DomainException
     */
    public function cancel(PurchaseRequisition $requisition, User $actor, string $reason): PurchaseRequisition
    {
        $trimmedReason = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $reason) ?? trim($reason);

        if (mb_strlen($trimmedReason) < 5) {
            throw new DomainException(__('validation.min.string', [
                'attribute' => __('purchasing.copy.cancellation_reason'),
                'min' => 5,
            ]));
        }

        return DB::transaction(function () use ($requisition, $actor, $trimmedReason): PurchaseRequisition {
            /** @var PurchaseRequisition $locked */
            $locked = PurchaseRequisition::query()
                ->whereKey($requisition->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PurchaseRequisition::STATUS_SUBMITTED) {
                throw new DomainException(__('purchasing.copy.pr_cancel_only_submitted'));
            }

            if (Quotation::withTrashed()->where('pr_id', $locked->id)->exists()) {
                throw new DomainException(__('purchasing.copy.pr_cancel_blocked_by_quotation'));
            }

            if ($locked->awards()->exists()) {
                throw new DomainException(__('purchasing.copy.pr_cancel_blocked_by_award'));
            }

            if (PurchaseOrder::withTrashed()->whereHas('quotations', fn ($q) => $q->where('pr_id', $locked->id))->exists()) {
                throw new DomainException(__('purchasing.copy.pr_cancel_blocked_by_award'));
            }

            $hasAnyInvitations = $locked->allInvitedSuppliers()->exists();
            $invitedSupplierIds = $hasAnyInvitations
                ? $locked->invitedSuppliers()
                    ->where('users.is_active', true)
                    ->pluck('users.id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
                : [];

            $cancelled = $this->stateEngine->transition(
                model: $locked,
                target: PurchaseRequisitionStatus::CANCELLED,
                actor: $actor,
                reason: $trimmedReason,
                metadata: [
                    'attributes' => [
                        'cancelled_at' => now(),
                        'cancelled_by' => $actor->id,
                        'cancellation_reason' => $trimmedReason,
                    ],
                ],
            );

            DB::afterCommit(function () use ($cancelled, $hasAnyInvitations, $invitedSupplierIds): void {
                Cache::forget('purchasing_dashboard_widgets');

                $recipientQuery = $hasAnyInvitations
                    ? User::query()->whereIn('id', $invitedSupplierIds)
                    : User::importEligible();

                $recipientQuery
                    ->select(['id', 'name', 'email', 'role', 'is_active', 'account_status'])
                    ->chunkById(500, function ($batch) use ($cancelled): void {
                        foreach ($batch as $supplier) {
                            Cache::forget('supplier_dashboard_widgets_'.$supplier->id);
                        }

                        if ($batch->isNotEmpty()) {
                            $this->notifications->send(
                                $batch,
                                'pr.cancelled',
                                'pr.cancelled:'.$cancelled->id,
                                'purchasing.notify.pr_cancelled_title',
                                'purchasing.notify.pr_cancelled_body',
                                route('supplier.requests.show', $cancelled, absolute: false),
                                'x-circle text-danger',
                                [
                                    'category' => NotificationCategory::QUOTATION,
                                    'pr_id' => $cancelled->id,
                                    'pr_hash' => $cancelled->hash,
                                    'pr_number' => $cancelled->pr_number,
                                ],
                                ['pr_number' => $cancelled->pr_number ?? '-'],
                            );
                        }
                    });
            });

            return $cancelled;
        });
    }
}
