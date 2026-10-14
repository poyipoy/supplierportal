<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Notifications\SystemNotification;
use App\Services\SupplierAudit\SupplierAuditAnswerService;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class SupplierAuditAnswerTest extends SupplierAuditTestCase
{
    public function test_partial_answer_fields_preserve_saved_values_and_explicit_null_clears(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $cid = $this->firstCriterionId($audit);
        app(SupplierAuditAnswerService::class)->save($supplier, $audit, [$cid => ['answer' => 'YES', 'score' => 4]], false);
        $url = route('local-supplier.supplier-audits.update', $audit);

        foreach ([['answer' => 'YES'], ['score' => 5], []] as $input) {
            $this->actingAs($supplier)->putJson($url, ['action' => 'draft', 'answers' => [$cid => $input]])->assertOk();
            $this->assertSame('YES', $this->answerFor($audit, $cid)->answer);
            $this->assertSame(array_key_exists('answer', $input) ? 4 : 5, $this->answerFor($audit, $cid)->score);
        }

        $this->actingAs($supplier)->putJson($url, ['action' => 'draft', 'answers' => [$cid => ['score' => null]]])->assertOk();
        $this->assertSame('YES', $this->answerFor($audit, $cid)->answer);
        $this->assertNull($this->answerFor($audit, $cid)->score);
        $this->actingAs($supplier)->putJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => null]]])->assertOk();
        $this->assertNull($this->answerFor($audit, $cid)->answer);
        $this->assertNull($this->answerFor($audit, $cid)->score);
    }

    public function test_oversized_answer_payload_is_rejected_without_changing_existing_answers(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $cid = $this->firstCriterionId($audit);
        app(SupplierAuditAnswerService::class)->save($supplier, $audit, [$cid => ['answer' => 'YES', 'score' => 4]], false);
        $answers = array_fill(1, 151, ['answer' => 'NO']);
        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft', 'answers' => $answers,
        ])->assertUnprocessable()->assertJsonValidationErrors('answers');
        $this->assertSame('YES', $this->answerFor($audit, $cid)->answer);
        $this->assertSame(4, $this->answerFor($audit, $cid)->score);
    }

    public function test_direct_answer_service_rejects_oversized_payload(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('supplier_audit.errors.answer_limit', ['count' => 150]));
        app(SupplierAuditAnswerService::class)->save($supplier, $audit, array_fill(1, 151, []), false);
    }

    public function test_edit_page_renders_wizard_with_all_criteria(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);

        $response = $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.edit', $audit))->assertOk();
        $html = $response->getContent();

        $this->assertSame(16, substr_count($html, 'data-section-code="'));
        $this->assertStringNotContainsString('data-wizard-step', $html);
        $this->assertSame(121, substr_count($html, 'data-criterion-row="'));
        $response->assertSee('data-async-submit', false)
            ->assertSee(route('local-supplier.supplier-audits.update', $audit), false);
    }

    public function test_draft_accepts_yes_without_score_and_starts_draft_once(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $cid = $this->firstCriterionId($audit);

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft',
            'answers' => [$cid => ['answer' => 'YES', 'score' => '']],
        ])->assertOk()->assertJson(['redirect' => route('local-supplier.supplier-audits.edit', $audit)]);

        $this->assertSame(SupplierAudit::STATUS_DRAFT, $audit->fresh()->status);
        $this->assertSame('YES', $this->answerFor($audit, $cid)->answer);
        $this->assertNull($this->answerFor($audit, $cid)->score);

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft',
            'answers' => [$cid => ['answer' => 'YES', 'score' => 3]],
        ])->assertOk();

        $this->assertSame(3, $this->answerFor($audit, $cid)->score);
        $this->assertSame(1, $audit->statusHistories()->where('event', SupplierAuditStatusHistory::EVENT_DRAFT_STARTED)->count());
    }

    public function test_draft_still_enforces_score_rules(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $cid = $this->firstCriterionId($audit);
        $url = route('local-supplier.supplier-audits.update', $audit);

        $this->actingAs($supplier)->putJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => 'YES', 'score' => 6]]])
            ->assertUnprocessable()->assertJsonValidationErrors("answers.$cid.score");
        $this->actingAs($supplier)->putJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => 'NO', 'score' => 2]]])
            ->assertUnprocessable()->assertJsonValidationErrors("answers.$cid.score");
        $this->actingAs($supplier)->putJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => 'MAYBE']]])
            ->assertUnprocessable()->assertJsonValidationErrors("answers.$cid.answer");

        $this->assertNull($this->answerFor($audit, $cid)->answer);
    }

    public function test_answers_for_foreign_criteria_are_rejected(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft',
            'answers' => [999999 => ['answer' => 'NO']],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers');
    }

    public function test_submit_rejects_incomplete_answers(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $answers = $this->completeAnswers($audit);
        $ids = array_keys($answers);
        $answers[$ids[0]] = ['answer' => '', 'score' => ''];
        $answers[$ids[1]] = ['answer' => 'YES', 'score' => ''];

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'submit',
            'answers' => $answers,
        ])->assertUnprocessable()->assertJsonValidationErrors(["answers.{$ids[0]}.answer", "answers.{$ids[1]}.score"]);

        $this->assertNotSame(SupplierAudit::STATUS_SUBMITTED, $audit->fresh()->status);
    }

    public function test_submit_service_rejects_rows_left_blank_in_database(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $answers = $this->completeAnswers($audit);
        array_shift($answers);

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'submit',
            'answers' => $answers,
        ])->assertUnprocessable();

        $this->assertSame(SupplierAudit::STATUS_ASSIGNED, $audit->fresh()->status);
    }

    public function test_submit_locks_form_and_notifies_purchasing(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $answers = $this->completeAnswers($audit);
        $ids = array_keys($answers);
        $answers[$ids[0]] = ['answer' => 'NO', 'score' => ''];

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'submit',
            'answers' => $answers,
        ])->assertOk()->assertJson(['redirect' => route('local-supplier.supplier-audits.show', $audit)]);

        $audit->refresh();
        $this->assertSame(SupplierAudit::STATUS_SUBMITTED, $audit->status);
        $this->assertNotNull($audit->submitted_at);
        $this->assertNull($this->answerFor($audit, (int) $ids[0])->score);
        Notification::assertSentTo($this->purchasing, SystemNotification::class, fn ($n) => $n->event() === 'supplier_audit.submitted');

        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.edit', $audit))
            ->assertRedirect(route('local-supplier.supplier-audits.show', $audit));
        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft',
            'answers' => [$ids[0] => ['answer' => 'YES', 'score' => 5]],
        ])->assertForbidden();
        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.show', $audit))
            ->assertOk()->assertSee(__('supplier_audit.labels.locked'));
    }

    public function test_revision_cycle_allows_resubmission(): void
    {
        $supplier = $this->supplier();
        $audit = $this->submitComplete($this->assign($supplier), $supplier);
        app(SupplierAuditReviewService::class)->requestRevision($this->purchasing, $audit, 'Perbaiki bagian 3');
        $audit->refresh();
        $this->assertSame(SupplierAudit::STATUS_REVISION_REQUESTED, $audit->status);

        $cid = $this->firstCriterionId($audit);
        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.edit', $audit))
            ->assertOk()->assertSee('Perbaiki bagian 3');

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft',
            'answers' => [$cid => ['answer' => 'NO']],
        ])->assertOk();
        $this->assertSame(SupplierAudit::STATUS_REVISION_REQUESTED, $audit->fresh()->status, 'Draft keeps the revision status.');

        $this->actingAs($supplier)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'submit',
            'answers' => $this->completeAnswers($audit),
        ])->assertOk();

        $this->assertSame(SupplierAudit::STATUS_SUBMITTED, $audit->fresh()->status);
        $lastSubmit = $audit->statusHistories()->where('event', SupplierAuditStatusHistory::EVENT_SUBMITTED)->reorder()->latest('id')->first();
        $this->assertSame(SupplierAudit::STATUS_REVISION_REQUESTED, $lastSubmit->from_status);
    }
}
