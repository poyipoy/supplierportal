<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LocalFinanceAuditService
{
    public function recordCreatedBatch(iterable $subjects, string $action, User $actor): void
    {
        $rows = [];
        $castSnapshots = [];
        $createdAt = now();
        foreach ($subjects as $subject) {
            $attributes = $subject->getAttributes();
            // Fresh PO/GR models have no loaded relations or appended/hidden fields.
            // Reuse their identical date/decimal casts within this bounded batch,
            // keeping every raw business attribute and each model's ID distinct.
            if (($subject instanceof LocalPurchaseOrder || $subject instanceof LocalGoodsReceipt)
                && $subject->getRelations() === [] && $subject->getAppends() === []
                && $subject->getHidden() === [] && $subject->getVisible() === []
                && array_intersect($subject->getMutatedAttributes(), array_keys($attributes)) === []) {
                $fields = array_flip(array_diff(array_unique(array_merge(array_keys($subject->getCasts()), $subject->getDates())), ['id']));
                $signature = $subject::class.json_encode(array_intersect_key($attributes, $fields), JSON_THROW_ON_ERROR);
                $castSnapshots[$signature] ??= array_intersect_key($subject->toArray(), $fields);
                $snapshot = array_replace($attributes, $castSnapshots[$signature]);
                $snapshot['id'] = (int) $subject->getKey();
            } else {
                $snapshot = $subject->toArray();
            }
            $rows[] = ['auditable_type' => $subject::class, 'auditable_id' => $subject->getKey(), 'action' => $action,
                'actor_id' => $actor->id, 'before_values' => null, 'after_values' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'metadata' => null, 'created_at' => $createdAt];
        }
        if ($rows) {
            DB::table('local_finance_audit_logs')->insert($rows);
        }
    }

    public function record(Model $subject, string $action, User $actor, ?array $before = null, ?array $after = null, ?array $metadata = null): void
    {
        LocalFinanceAuditLog::create([
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'actor_id' => $actor->id,
            'before_values' => $before,
            'after_values' => $after,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
