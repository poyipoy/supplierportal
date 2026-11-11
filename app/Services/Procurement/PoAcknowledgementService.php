<?php

namespace App\Services\Procurement;

use App\Enums\PoAcknowledgementStatus;
use App\Exceptions\BusinessRuleArgumentException;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\BusinessTime;
use App\Support\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PoAcknowledgementService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Acknowledge an Import PurchaseOrder or propose a revised delivery promise date.
     *
     * @param  array{mode?: string, supplier_promised_date?: string|null, acknowledgement_notes?: string|null}  $data
     */
    public function acknowledgeImportPo(PurchaseOrder $po, User $actor, array $data): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('acknowledge', $po);

        $updatedPo = DB::transaction(function () use ($po, $actor, $data): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->whereKey($po->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('acknowledge', $locked);

            if (! $locked->canAcknowledge()) {
                throw new BusinessRuleException(__('purchasing.po_ack.errors.cannot_acknowledge'));
            }

            $mode = trim((string) ($data['mode'] ?? ''));
            $promisedDate = isset($data['supplier_promised_date']) && trim((string) $data['supplier_promised_date']) !== ''
                ? BusinessTime::parseDate((string) $data['supplier_promised_date'])->toDateString()
                : null;
            $notes = isset($data['acknowledgement_notes']) && trim((string) $data['acknowledgement_notes']) !== ''
                ? trim((string) $data['acknowledgement_notes'])
                : null;

            $targetDate = $locked->estimated_arrival?->format('Y-m-d');
            $isChangeProposal = $mode === 'propose_change'
                || ($mode === '' && $promisedDate !== null && $targetDate !== null && $promisedDate !== $targetDate);

            if ($isChangeProposal) {
                if ($promisedDate === null) {
                    throw new BusinessRuleArgumentException(__('purchasing.po_ack.errors.promised_date_required'));
                }
                if ($promisedDate < BusinessTime::today()->toDateString()) {
                    throw new BusinessRuleArgumentException(__('purchasing.po_ack.errors.promised_date_past'));
                }
                if ($notes === null) {
                    throw new BusinessRuleArgumentException(__('purchasing.po_ack.errors.notes_required_for_change'));
                }

                $locked->update([
                    'acknowledgement_status' => PoAcknowledgementStatus::CHANGE_REQUESTED,
                    'acknowledged_at' => BusinessTime::now(),
                    'acknowledged_by' => $actor->id,
                    'supplier_promised_date' => $promisedDate,
                    'acknowledgement_notes' => $notes,
                ]);
            } else {
                $locked->update([
                    'acknowledgement_status' => PoAcknowledgementStatus::ACKNOWLEDGED,
                    'acknowledged_at' => BusinessTime::now(),
                    'acknowledged_by' => $actor->id,
                    'supplier_promised_date' => $mode === 'confirm' ? $targetDate : ($promisedDate ?? $targetDate),
                    'acknowledgement_notes' => $notes,
                ]);
            }

            return $locked->fresh();
        });

        $purchasingUsers = User::query()
            ->where('role', 'purchasing')
            ->where('is_active', true)
            ->get();

        $isChange = $updatedPo->acknowledgementStatusEnum() === PoAcknowledgementStatus::CHANGE_REQUESTED;
        $this->notifications->send(
            $purchasingUsers,
            $isChange ? 'po.ack_change_requested' : 'po.acknowledged',
            ($isChange ? 'po.ack_change_requested:' : 'po.acknowledged:').$updatedPo->id.':'.($updatedPo->acknowledged_at?->timestamp ?? 0),
            $isChange ? 'purchasing.po_ack.notify.change_requested_title' : 'purchasing.po_ack.notify.acknowledged_title',
            $isChange ? 'purchasing.po_ack.notify.change_requested_body' : 'purchasing.po_ack.notify.acknowledged_body',
            route('purchasing.purchase-orders.show', $updatedPo, absolute: false),
            $isChange ? 'calendar-clock text-warning' : 'circle-check text-success',
            [
                'category' => NotificationCategory::OTHER,
                'po_id' => $updatedPo->id,
                'po_number' => $updatedPo->po_number,
            ],
            [
                'po' => $updatedPo->po_number,
                'date' => $updatedPo->supplier_promised_date?->format('Y-m-d') ?? '-',
            ],
        );

        return $updatedPo;
    }

    /**
     * Purchasing accepts or rejects a supplier's proposed promise date on an Import PO.
     *
     * @param  array{decision: string, response_notes?: string|null}  $data
     */
    public function respondImportPromiseChange(PurchaseOrder $po, User $actor, array $data): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('respondPromiseChange', $po);

        $decision = (string) ($data['decision'] ?? '');
        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw new BusinessRuleArgumentException(__('purchasing.po_ack.errors.invalid_decision'));
        }

        $updatedPo = DB::transaction(function () use ($po, $actor, $decision, $data): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->whereKey($po->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('respondPromiseChange', $locked);

            if ($locked->acknowledgementStatusEnum() !== PoAcknowledgementStatus::CHANGE_REQUESTED) {
                throw new BusinessRuleException(__('purchasing.po_ack.errors.no_pending_change'));
            }

            $notes = isset($data['response_notes']) && trim((string) $data['response_notes']) !== ''
                ? trim((string) $data['response_notes'])
                : $locked->acknowledgement_notes;

            if ($decision === 'accept') {
                $locked->update([
                    'estimated_arrival' => $locked->supplier_promised_date ?? $locked->estimated_arrival,
                    'acknowledgement_status' => PoAcknowledgementStatus::ACCEPTED_CHANGE,
                    'acknowledgement_notes' => $notes,
                ]);
            } else {
                $locked->update([
                    'acknowledgement_status' => PoAcknowledgementStatus::PENDING,
                    'supplier_promised_date' => null,
                    'acknowledgement_notes' => $notes,
                ]);
            }

            return $locked->fresh(['supplier']);
        });

        if ($updatedPo->supplier) {
            $accepted = $decision === 'accept';
            $this->notifications->send(
                [$updatedPo->supplier],
                $accepted ? 'po.promise_change_accepted' : 'po.promise_change_rejected',
                ($accepted ? 'po.promise_change_accepted:' : 'po.promise_change_rejected:').$updatedPo->id.':'.BusinessTime::now()->timestamp,
                $accepted ? 'purchasing.po_ack.notify.accepted_title' : 'purchasing.po_ack.notify.rejected_title',
                $accepted ? 'purchasing.po_ack.notify.accepted_body' : 'purchasing.po_ack.notify.rejected_body',
                route('supplier.purchase-orders.show', $updatedPo, absolute: false),
                $accepted ? 'circle-check text-success' : 'circle-x text-danger',
                [
                    'category' => NotificationCategory::OTHER,
                    'po_id' => $updatedPo->id,
                    'po_number' => $updatedPo->po_number,
                ],
                [
                    'po' => $updatedPo->po_number,
                ],
            );
        }

        return $updatedPo;
    }
}
