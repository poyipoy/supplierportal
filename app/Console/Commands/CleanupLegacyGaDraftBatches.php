<?php

namespace App\Console\Commands;

use App\Models\GaClaim;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CleanupLegacyGaDraftBatches extends Command
{
    protected $signature = 'payments:cleanup-legacy-ga-drafts {--batch-id=* : Exact legacy batch IDs approved for cleanup} {--execute : Delete the explicitly selected batches; otherwise preflight only}';

    protected $description = 'Preflight and safely hard-delete selected legacy GA DRAFT reservations';

    public function handle(): int
    {
        $ids = $this->option('batch-id');
        if (($this->option('execute') && $ids === []) || collect($ids)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Execution requires explicit positive --batch-id values from the legacy preflight.');

            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($ids) {
                $query = PaymentBatch::query()->where('batch_type', PaymentBatch::TYPE_GA)->where('status', PaymentBatch::STATUS_DRAFT)->orderBy('id');
                if ($ids !== []) {
                    $query->whereIn('id', $ids);
                }
                $batches = $query->lockForUpdate()->get();
                if ($ids !== [] && $batches->count() !== count(array_unique($ids))) {
                    throw new RuntimeException('A selected batch is missing or is no longer GA DRAFT. No batches deleted.');
                }
                // Preflight every target before deleting any target: conflicts roll back the entire operation.
                foreach ($batches as $batch) {
                    $groups = $batch->groups()->orderBy('id')->lockForUpdate()->get();
                    $items = DB::table('payment_items')->whereIn('payment_group_id', $groups->modelKeys())->orderBy('id')->lockForUpdate()->get();
                    $conflict = $batch->finalized_at || $batch->finalized_by || $batch->paid_at;
                    foreach ($groups as $group) {
                        $conflict = $conflict || $group->status === PaymentGroup::STATUS_PAID;
                        foreach (['voucher_number', 'voucher_date', 'transfer_reference', 'transfer_date', 'paid_by', 'paid_at', 'payment_notes'] as $field) {
                            $conflict = $conflict || $group->$field !== null;
                        }
                        $conflict = $conflict || $group->payee_type !== 'employee';
                    }
                    $itemIds = $items->pluck('id');
                    $conflict = $conflict || $items->contains(fn ($item) => $item->payable_type !== GaClaim::class);
                    $claims = GaClaim::whereIn('id', $items->pluck('payable_id'))->orderBy('id')->lockForUpdate()->get();
                    $conflict = $conflict || $claims->contains(fn ($claim) => $claim->status === GaClaim::STATUS_PAID || $claim->paid_at !== null);
                    foreach ([PaymentBatch::class => [$batch->id], PaymentGroup::class => $groups->modelKeys(), PaymentItem::class => $itemIds->all()] as $type => $subjectIds) {
                        $conflict = $conflict || DB::table('attachments')->where('attachable_type', $type)->whereIn('attachable_id', $subjectIds)->exists();
                    }
                    $conflict = $conflict || DB::table('local_invoice_vouchers')->where(function ($q) use ($batch, $groups, $itemIds) {
                        $q->where('payment_batch_id', $batch->id)->orWhereIn('payment_group_id', $groups->modelKeys())->orWhereIn('payment_item_id', $itemIds);
                    })->exists();
                    $conflict = $conflict || DB::table('local_invoice_payments')->whereIn('payment_item_id', $itemIds)->exists();
                    if ($conflict) {
                        throw new RuntimeException("Batch {$batch->id} ({$batch->batch_number}) has conflicting payment/voucher/dependency artifacts. No batches deleted; investigate manually.");
                    }
                    $this->line("{$batch->id} | {$batch->batch_number} | groups={$groups->count()} | items={$items->count()}");
                }
                $this->info('GA DRAFT targets: '.$batches->count());
                if (! $this->option('execute')) {
                    $this->info('Preflight only. No records deleted.');

                    return self::SUCCESS;
                }
                foreach ($batches as $batch) {
                    $groupIds = $batch->groups()->pluck('id');
                    DB::table('payment_items')->whereIn('payment_group_id', $groupIds)->delete();
                    DB::table('payment_groups')->whereIn('id', $groupIds)->delete();
                    DB::table('payment_batches')->where('id', $batch->id)->where('batch_type', PaymentBatch::TYPE_GA)->where('status', PaymentBatch::STATUS_DRAFT)->delete();
                }
                $this->info('Deleted selected legacy GA DRAFT batches. Claims and their history are preserved.');

                return self::SUCCESS;
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
