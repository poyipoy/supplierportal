<?php

namespace App\Services\Materials;

use App\Models\HsCodeRule;
use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Q3 guarded hard delete for Material Masters and HS Code Rules (I-13, I-16).
 *
 * Phase 1 — one transaction: lock the row, count references, and either
 * return a refusal without writing anything, or delete the row together with
 * its `deleted` audit (both commit or neither does).
 * Phase 2 — after Phase 1 has finished: a refusal (counted references, or the
 * FK RESTRICT backstop when a concurrent child committed first) is audited as
 * `delete_refused` with an auto-committed insert, so a refusal audit is never
 * inside a transaction that rolls back.
 */
class MasterDataDeletionService
{
    public function delete(MaterialMaster|HsCodeRule $entity, User $actor): DeletionOutcome
    {
        try {
            $outcome = DB::transaction(function () use ($entity, $actor): DeletionOutcome {
                $locked = $entity->newQuery()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $counts = $this->referenceCounts($locked);

                if (array_sum($counts) > 0) {
                    return DeletionOutcome::refused($counts, 'referenced');
                }

                $before = $locked->getAttributes();
                $locked->delete();
                MasterDataAuditLog::record($locked, MasterDataAuditLog::DELETED, $before, null, $actor);

                return DeletionOutcome::deleted();
            });
        } catch (QueryException $exception) {
            if (! $this->isForeignKeyRestrict($exception)) {
                throw $exception;
            }
            $outcome = DeletionOutcome::refused($this->referenceCounts($entity), 'fk_restrict');
        }

        if (! $outcome->deleted) {
            $this->auditRefusal($entity, $outcome, $actor);
        }

        return $outcome;
    }

    /**
     * Every table that references the master (FK RESTRICT children).
     *
     * @return array<string, int>
     */
    protected function referenceCounts(Model $entity): array
    {
        if ($entity instanceof HsCodeRule) {
            return ['pr_items' => DB::table('pr_items')->where('hs_code_rule_id', $entity->getKey())->count()];
        }

        return [
            'pr_items' => DB::table('pr_items')->where('material_master_id', $entity->getKey())->count(),
            'aliases' => DB::table('material_aliases')->where('material_master_id', $entity->getKey())->count(),
            'reference_prices' => DB::table('material_reference_prices')->where('material_master_id', $entity->getKey())->count(),
        ];
    }

    private function auditRefusal(Model $entity, DeletionOutcome $outcome, User $actor): void
    {
        try {
            MasterDataAuditLog::record($entity, MasterDataAuditLog::DELETE_REFUSED, null, [
                'reason' => $outcome->reason,
                'references' => $outcome->counts,
            ], $actor);
        } catch (Throwable $exception) {
            // The refusal still reaches the user; the audit failure is reported.
            report($exception);
        }
    }

    private function isForeignKeyRestrict(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1451;
    }
}
