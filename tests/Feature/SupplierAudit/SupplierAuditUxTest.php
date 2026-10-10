<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use App\Support\BusinessTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Tests\Support\NativeFileFixtures;

class SupplierAuditUxTest extends SupplierAuditTestCase
{
    public function test_autosave_saves_partial_answers_and_returns_progress(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $cid = $this->firstCriterionId($audit);

        $response = $this->actingAs($supplier)->patchJson(route('local-supplier.supplier-audits.autosave', $audit), [
            'action' => 'draft',
            'answers' => [$cid => ['answer' => 'NO', 'score' => null]],
        ])->assertOk()->assertJsonStructure(['saved_at', 'saved_label', 'progress' => ['filled', 'total'], 'status']);

        $this->assertSame(1, $response->json('progress.filled'));
        $this->assertSame(121, $response->json('progress.total'));
        $this->assertSame(SupplierAudit::STATUS_DRAFT, $response->json('status'));
        $this->assertSame('NO', $this->answerFor($audit, $cid)->answer);

        $this->actingAs($supplier)->patchJson(route('local-supplier.supplier-audits.autosave', $audit), [
            'action' => 'draft',
            'answers' => [$cid => ['answer' => 'YES', 'score' => null]],
        ])->assertOk();
        $this->assertSame(1, $audit->statusHistories()->where('event', SupplierAuditStatusHistory::EVENT_DRAFT_STARTED)->count());
    }

    public function test_autosave_enforces_rules_ownership_and_lock(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $cid = $this->firstCriterionId($audit);
        $url = route('local-supplier.supplier-audits.autosave', $audit);

        $this->actingAs($supplier)->patchJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => 'NO', 'score' => 3]]])
            ->assertUnprocessable()->assertJsonValidationErrors("answers.$cid.score");
        $this->actingAs($supplier)->patchJson($url, ['action' => 'submit', 'answers' => $this->completeAnswers($audit)])
            ->assertUnprocessable();
        $this->assertNotSame(SupplierAudit::STATUS_SUBMITTED, $audit->fresh()->status, 'Autosave never submits.');

        $this->actingAs($this->supplier())->patchJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => 'NO']]])->assertForbidden();

        $this->submitComplete($audit, $supplier);
        $this->actingAs($supplier)->patchJson($url, ['action' => 'draft', 'answers' => [$cid => ['answer' => 'NO']]])->assertForbidden();
    }

    public function test_purchasing_index_queues_counts_and_default_tab(): void
    {
        $submittedSupplier = $this->supplier();
        $submitted = $this->submitComplete($this->assign($submittedSupplier), $submittedSupplier);
        $waiting = $this->assign($this->supplier(), BusinessTime::today()->addDays(5)->toDateString());
        $late = $this->assign($this->supplier(), BusinessTime::today()->subDays(3)->toDateString());

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index'));
        $this->assertMatchesRegularExpression('/aria-current="page"\s+data-queue-tab="review"/', $response->getContent());
        $response->assertOk()
            ->assertSee(route('purchasing.supplier-audits.show', $submitted), false)
            ->assertDontSee(route('purchasing.supplier-audits.show', $waiting), false)
            ->assertSee('data-queue-count="review">1<', false)
            ->assertSee('data-queue-count="waiting">2<', false)
            ->assertSee('data-queue-count="late">1<', false)
            ->assertSee('data-queue-count="all">3<', false)
            ->assertSee('121/121');

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index', ['queue' => 'late']))
            ->assertOk()
            ->assertSee(route('purchasing.supplier-audits.show', $late), false)
            ->assertSee(trans_choice('supplier_audit.deadline_relative.overdue', 3, ['count' => 3]));
    }

    public function test_purchasing_index_progress_does_not_query_per_row(): void
    {
        foreach (range(1, 4) as $i) {
            $this->assign($this->supplier());
        }

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'supplier_audit_answers')) {
                $queries++;
            }
        });
        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index', ['queue' => 'all']))->assertOk();

        $this->assertLessThanOrEqual(1, $queries, 'Progress counts must come from one aggregated query.');
    }

    public function test_review_queue_empty_state_links_to_waiting(): void
    {
        $this->assign($this->supplier());

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index'))
            ->assertOk()
            ->assertSee(__('supplier_audit.queues.empty_review'))
            ->assertSee(route('purchasing.supplier-audits.index', ['queue' => 'waiting']), false);
    }

    public function test_show_next_step_panel_follows_status(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.show', $audit))
            ->assertOk()->assertSee('data-next-step="assigned"', false)
            ->assertDontSee('data-result-form', false)
            ->assertSee('data-supplier-audit-flow', false);

        $audit = $this->submitComplete($audit, $supplier);
        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.show', $audit))
            ->assertOk()->assertSee('data-next-step="submitted"', false)
            ->assertSee(route('purchasing.export.supplier-audits.detail', $audit), false)
            ->assertSee('data-result-form="publish"', false)
            ->assertSee('data-answer-filter="low"', false);

        app(SupplierAuditReviewService::class)->publishResult($this->purchasing, $audit, UploadedFile::fake()->createWithContent('hasil.pdf', NativeFileFixtures::pdf()));
        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.show', $audit))
            ->assertOk()->assertSee('data-next-step="result_published"', false)
            ->assertSee('data-result-form="replace"', false)
            ->assertSee('data-supplier-audit-results', false);
    }

    public function test_supplier_menu_badge_marks_pending_audit(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.index'))
            ->assertOk()->assertDontSee('data-supplier-audit-badge', false);

        $audit = $this->assign($supplier);
        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.index'))
            ->assertOk()->assertSee('data-supplier-audit-badge', false)
            ->assertSee(__('supplier_audit.badge.pending'));

        $this->submitComplete($audit, $supplier);
        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.index'))
            ->assertOk()->assertDontSee('data-supplier-audit-badge', false);
    }

    public function test_edit_page_exposes_autosave_and_single_page_navigation(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);

        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.edit', $audit))
            ->assertOk()
            // The URL is embedded in the @js() Alpine config, so compare it in the same encoding.
            ->assertSee(Str::between((string) Js::from(['autosaveUrl' => route('local-supplier.supplier-audits.autosave', $audit)]), "JSON.parse('{", "}')"), false)
            ->assertSee('data-autosave-status', false)
            ->assertSee(__('supplier_audit.score_scale.title'))
            ->assertSee(__('supplier_audit.form.jump_unanswered'))
            ->assertDontSee(__('supplier_audit.actions.save_draft'));
    }
}
