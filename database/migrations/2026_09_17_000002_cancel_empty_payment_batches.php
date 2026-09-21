<?php

use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Cancel any DRAFT payment batches where total_net_amount <= 0
        // or where there are no active items/groups remaining.
        $emptyBatches = PaymentBatch::where('status', PaymentBatch::STATUS_DRAFT)
            ->where(function ($q) {
                $q->where('total_net_amount', '<=', 0)
                    ->orWhereDoesntHave('groups', function ($g) {
                        $g->where('status', '!=', PaymentGroup::STATUS_CANCELLED)
                            ->whereHas('items', fn ($it) => $it->where('status', PaymentItem::STATUS_ACTIVE));
                    });
            })
            ->get();

        foreach ($emptyBatches as $batch) {
            $batch->update([
                'status' => PaymentBatch::STATUS_CANCELLED,
                'total_subtotal' => 0,
                'total_bank_fee' => 0,
                'total_net_amount' => 0,
            ]);
        }
    }

    public function down(): void
    {
        // One-way data consistency backfill
    }
};
