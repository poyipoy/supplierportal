<?php

namespace App\Services\Payment;

use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PaymentTransferGuard
{
    public function lockedBatches(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $batches = PaymentBatch::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if (count($ids) !== $batches->count()) {
            throw ValidationException::withMessages(['batch_ids' => __('finance_controls.transfer_status')]);
        }
        foreach ($batches as $batch) {
            $groups = $batch->groups()->orderBy('id')->lockForUpdate()->get();
            foreach ($groups as $group) {
                $group->setRelation('items', $group->items()->where('status', PaymentItem::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get());
            }
            $batch->setRelation('groups', $groups);
        }
        $this->assertEligible($batches);

        return $batches->sortBy([['created_at', 'asc'], ['id', 'asc']])->values();
    }

    public function assertEligible(Collection $batches): void
    {
        $items = new EloquentCollection($batches->flatMap(fn ($batch) => $batch->groups->flatMap(fn ($group) => $group->items))->all());
        $items->loadMissing('payable');
        foreach ($batches as $batch) {
            if ($batch->batch_type !== PaymentBatch::TYPE_SUPPLIER || ! in_array($batch->status, [PaymentBatch::STATUS_FINALIZED, PaymentBatch::STATUS_PARTIALLY_PAID, PaymentBatch::STATUS_PAID], true)) {
                throw ValidationException::withMessages(['batch_ids' => __('finance_controls.transfer_status')]);
            }
            $available = false;
            foreach ($batch->groups as $group) {
                if ($group->status === PaymentGroup::STATUS_CANCELLED) {
                    continue;
                }
                $active = $group->items->where('status', PaymentItem::STATUS_ACTIVE);
                if ($active->isEmpty()) {
                    continue;
                }
                foreach ($active as $item) {
                    if ($item->payable_type !== LocalInvoice::class || ! $item->payable
                        || (! $item->payable->isReadyToPay() && ! $item->payable->isPaid())) {
                        throw ValidationException::withMessages(['batch_ids' => __('finance_controls.transfer_partial')]);
                    }
                }
                $available = true;
            }
            if (! $available) {
                throw ValidationException::withMessages(['batch_ids' => __('finance_controls.transfer_empty')]);
            }
        }
    }

    public function markExported(Collection $batches, User $actor): void
    {
        foreach ($batches as $batch) {
            if (! $batch->transfer_exported_at) {
                $batch->update(['transfer_exported_at' => now(), 'transfer_exported_by' => $actor->id]);
            }
            app(LocalFinanceAuditService::class)->record($batch, 'transfer_export_requested', $actor, metadata: ['group_ids' => $batch->groups->where('status', '!=', PaymentGroup::STATUS_CANCELLED)->filter(fn ($group) => $group->items->where('status', PaymentItem::STATUS_ACTIVE)->isNotEmpty())->pluck('id')->all()]);
        }
    }
}
