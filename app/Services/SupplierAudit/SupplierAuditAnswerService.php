<?php

namespace App\Services\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use App\Models\SupplierAuditStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierAuditAnswerService
{
    public function __construct(private readonly SupplierAuditNotifier $notifier) {}

    /**
     * Simpan draft atau submit jawaban supplier.
     *
     * @param  array<int|string, array{answer?: ?string, score?: mixed}>  $answers  keyed by supplier_audit_criterion_id
     */
    public function save(User $supplier, SupplierAudit $audit, array $answers, bool $submit): SupplierAudit
    {
        [$locked, $history] = DB::transaction(function () use ($supplier, $audit, $answers, $submit) {
            $locked = SupplierAudit::whereKey($audit->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->supplier_id !== (int) $supplier->id || ! $locked->isEditableBySupplier()) {
                throw ValidationException::withMessages(['answers' => __('supplier_audit.errors.locked')]);
            }

            $rows = $locked->answers()->get()->keyBy('supplier_audit_criterion_id');

            foreach ($answers as $criterionId => $input) {
                /** @var SupplierAuditAnswer|null $row */
                $row = $rows->get((int) $criterionId);
                if ($row === null || ! is_array($input)) {
                    throw ValidationException::withMessages(['answers' => __('supplier_audit.errors.invalid_criterion')]);
                }

                [$answer, $score] = $this->normalize($input);
                if ($row->answer !== $answer || $row->score !== $score) {
                    $row->forceFill(['answer' => $answer, 'score' => $score])->save();
                }
            }

            if ($submit) {
                $this->assertComplete($rows);

                $from = $locked->status;
                $locked->update([
                    'status' => SupplierAudit::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                ]);
                $history = $locked->statusHistories()->create([
                    'from_status' => $from,
                    'to_status' => SupplierAudit::STATUS_SUBMITTED,
                    'event' => SupplierAuditStatusHistory::EVENT_SUBMITTED,
                    'actor_id' => $supplier->id,
                ]);

                return [$locked, $history];
            }

            if ($locked->status === SupplierAudit::STATUS_ASSIGNED) {
                $locked->update(['status' => SupplierAudit::STATUS_DRAFT]);
                $locked->statusHistories()->create([
                    'from_status' => SupplierAudit::STATUS_ASSIGNED,
                    'to_status' => SupplierAudit::STATUS_DRAFT,
                    'event' => SupplierAuditStatusHistory::EVENT_DRAFT_STARTED,
                    'actor_id' => $supplier->id,
                ]);
            }

            return [$locked, null];
        });

        if ($history !== null) {
            $this->notifier->submitted($locked, $history);
        }

        return $locked;
    }

    /**
     * D3: Tidak → score null (dipaksa); Ya → 1–5 atau null; belum dijawab → score null.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function normalize(array $input): array
    {
        $answer = $input['answer'] ?? null;
        $answer = in_array($answer, SupplierAuditAnswer::ANSWERS, true) ? $answer : null;

        if ($answer !== SupplierAuditAnswer::ANSWER_YES) {
            return [$answer, null];
        }

        $score = $input['score'] ?? null;
        if ($score === null || $score === '') {
            return [$answer, null];
        }

        $score = filter_var($score, FILTER_VALIDATE_INT);
        if ($score === false || $score < SupplierAuditAnswer::SCORE_MIN || $score > SupplierAuditAnswer::SCORE_MAX) {
            throw ValidationException::withMessages(['answers' => __('supplier_audit.errors.score_range')]);
        }

        return [$answer, $score];
    }

    private function assertComplete($rows): void
    {
        $messages = [];
        foreach ($rows->sortBy('sort_order_snapshot') as $row) {
            $label = __('supplier_audit.fields.criterion_ref', [
                'section' => $row->section_code_snapshot,
                'number' => $row->criterion_number_snapshot,
            ]);

            if ($row->answer === null) {
                $messages['answers.'.$row->supplier_audit_criterion_id.'.answer'] = __('supplier_audit.errors.answer_required', ['criterion' => $label]);
            } elseif ($row->answer === SupplierAuditAnswer::ANSWER_YES && $row->score === null) {
                $messages['answers.'.$row->supplier_audit_criterion_id.'.score'] = __('supplier_audit.errors.score_required', ['criterion' => $label]);
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
