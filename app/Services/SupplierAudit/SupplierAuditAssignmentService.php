<?php

namespace App\Services\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use App\Models\SupplierAuditCriterion;
use App\Models\SupplierAuditStatusHistory;
use App\Models\SupplierAuditTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierAuditAssignmentService
{
    public const REJECT_ACTIVE_AUDIT = 'active_audit';

    public const REJECT_NOT_ELIGIBLE = 'not_eligible';

    public function __construct(private readonly SupplierAuditNotifier $notifier) {}

    /**
     * Membuat satu audit per supplier (D12). Supplier yang masih punya audit aktif (D7) atau
     * tidak eligible ditolak; sisanya tetap dibuat.
     *
     * @param  list<int>  $supplierIds
     * @return array{created: Collection<int, SupplierAudit>, rejected: list<array{name: string, reason: string}>}
     */
    public function assign(User $actor, array $supplierIds, string $periodLabel, ?string $dueDate): array
    {
        $template = SupplierAuditTemplate::activeFor();
        if ($template === null) {
            throw ValidationException::withMessages(['supplier_ids' => __('supplier_audit.errors.no_active_template')]);
        }

        $criteria = SupplierAuditCriterion::query()
            ->whereHas('section', fn ($query) => $query->where('supplier_audit_template_id', $template->id))
            ->with('section.parent')
            ->orderBy('sort_order')
            ->get();

        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));

        $result = DB::transaction(function () use ($actor, $supplierIds, $periodLabel, $dueDate, $template, $criteria) {
            // Kunci baris user supplier (urut id) agar penugasan paralel untuk supplier yang sama berurutan (D7).
            $eligible = User::localEligible()
                ->whereKey($supplierIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $eligible->load('supplier');

            $rejected = [];
            $missing = array_diff($supplierIds, $eligible->keys()->all());
            if ($missing !== []) {
                User::whereKey($missing)->with('supplier')->orderBy('id')->get()
                    ->each(function (User $user) use (&$rejected) {
                        $rejected[] = ['name' => $this->displayName($user), 'reason' => self::REJECT_NOT_ELIGIBLE];
                    });
            }

            $withActiveAudit = SupplierAudit::query()
                ->whereIn('supplier_id', $eligible->keys())
                ->active()
                ->pluck('supplier_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $created = collect();
            foreach ($eligible as $supplier) {
                if (in_array((int) $supplier->id, $withActiveAudit, true)) {
                    $rejected[] = ['name' => $this->displayName($supplier), 'reason' => self::REJECT_ACTIVE_AUDIT];

                    continue;
                }

                $audit = SupplierAudit::create([
                    'supplier_id' => $supplier->id,
                    'supplier_audit_template_id' => $template->id,
                    'period_label' => $periodLabel,
                    'due_date' => $dueDate,
                    'status' => SupplierAudit::STATUS_ASSIGNED,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
                $audit->setRelation('supplier', $supplier);

                $this->snapshotAnswers($audit, $criteria);

                $audit->statusHistories()->create([
                    'from_status' => null,
                    'to_status' => SupplierAudit::STATUS_ASSIGNED,
                    'event' => SupplierAuditStatusHistory::EVENT_ASSIGNED,
                    'actor_id' => $actor->id,
                ]);

                $this->notifier->assigned($audit);
                $created->push($audit);
            }

            return ['created' => $created, 'rejected' => $rejected];
        });

        if ($result['created']->isEmpty()) {
            throw ValidationException::withMessages([
                'supplier_ids' => __('supplier_audit.errors.none_assigned', [
                    'list' => collect($result['rejected'])
                        ->map(fn (array $row) => $row['name'].' ('.__('supplier_audit.reject_reasons.'.$row['reason']).')')
                        ->implode(', '),
                ]),
            ]);
        }

        return $result;
    }

    /** Snapshot seluruh kriteria template saat penugasan (rencana §3.2). */
    private function snapshotAnswers(SupplierAudit $audit, Collection $criteria): void
    {
        $now = now();
        $rows = $criteria->map(function (SupplierAuditCriterion $criterion) use ($audit, $now) {
            $section = $criterion->section;
            $parent = $section->parent;

            return [
                'supplier_audit_id' => $audit->id,
                'supplier_audit_criterion_id' => $criterion->id,
                'parent_section_code_snapshot' => $parent?->code,
                'parent_section_title_snapshot' => $parent?->title,
                'section_code_snapshot' => $section->code,
                'section_title_snapshot' => $section->title,
                'sheet_number_snapshot' => $section->sheet_number,
                'criterion_number_snapshot' => $criterion->number,
                'sort_order_snapshot' => $criterion->sort_order,
                'criterion_text_snapshot' => $criterion->text,
                'answer' => null,
                'score' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            SupplierAuditAnswer::insert($chunk);
        }
    }

    private function displayName(User $user): string
    {
        return (string) ($user->supplier?->company_name ?: $user->name);
    }
}
