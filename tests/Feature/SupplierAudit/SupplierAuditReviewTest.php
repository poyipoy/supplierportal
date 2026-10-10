<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\Attachment;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use App\Support\BusinessTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeFileFixtures;

class SupplierAuditReviewTest extends SupplierAuditTestCase
{
    private function submitted(?User $supplier = null): array
    {
        $supplier ??= $this->supplier();

        return [$supplier, $this->submitComplete($this->assign($supplier), $supplier)];
    }

    private function pdf(string $name = 'hasil.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, NativeFileFixtures::pdf());
    }

    public function test_revision_requires_note_and_submitted_status(): void
    {
        [$supplier, $audit] = $this->submitted();

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.request-revision', $audit), ['note' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.request-revision', $audit), ['note' => 'Lengkapi bagian 5'])
            ->assertOk();

        $audit->refresh();
        $this->assertSame(SupplierAudit::STATUS_REVISION_REQUESTED, $audit->status);
        $this->assertSame('Lengkapi bagian 5', $audit->revision_note);
        Notification::assertSentTo($supplier, SystemNotification::class, fn ($n) => $n->event() === 'supplier_audit.revision_requested');

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.request-revision', $audit), ['note' => 'Lagi'])
            ->assertForbidden();
    }

    public function test_revision_can_set_new_deadline(): void
    {
        [, $audit] = $this->submitted();
        $newDate = BusinessTime::today()->addDays(7)->toDateString();

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.request-revision', $audit), [
            'note' => 'Perbaiki',
            'change_due_date' => '1',
            'due_date' => $newDate,
        ])->assertOk();

        $this->assertSame($newDate, $audit->fresh()->due_date->toDateString());
        $this->assertSame(1, $audit->statusHistories()->where('event', SupplierAuditStatusHistory::EVENT_DEADLINE_CHANGED)->count());
    }

    public function test_cancel_from_active_statuses_but_not_after_result(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.cancel', $audit), ['reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.cancel', $audit), ['reason' => 'Supplier tidak aktif lagi'])
            ->assertOk();

        $audit->refresh();
        $this->assertSame(SupplierAudit::STATUS_CANCELLED, $audit->status);
        $this->assertSame('Supplier tidak aktif lagi', $audit->cancel_reason);
        Notification::assertSentTo($supplier, SystemNotification::class, fn ($n) => $n->event() === 'supplier_audit.cancelled');

        [, $published] = $this->submitted();
        app(SupplierAuditReviewService::class)->publishResult($this->purchasing, $published, $this->pdf());
        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.cancel', $published), ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_upload_publishes_result_and_replacement_keeps_history(): void
    {
        [$supplier, $audit] = $this->submitted();

        $this->actingAs($this->purchasing)->post(route('purchasing.supplier-audits.result', $audit), [
            'result_file' => $this->pdf('hasil-v1.pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $audit->refresh();
        $this->assertSame(SupplierAudit::STATUS_RESULT_PUBLISHED, $audit->status);
        $this->assertNotNull($audit->result_published_at);
        $first = $audit->resultAttachments()->first();
        Storage::disk('private')->assertExists($first->file_path);
        Notification::assertSentTo($supplier, SystemNotification::class, fn ($n) => $n->event() === 'supplier_audit.result_published');

        $this->actingAs($this->purchasing)->post(route('purchasing.supplier-audits.result', $audit), [
            'result_file' => $this->pdf('hasil-v2.pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->purchasing)->post(route('purchasing.supplier-audits.result', $audit), [
            'result_file' => $this->pdf('hasil-v2.pdf'),
            'reason' => 'Salah bobot',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(2, $audit->resultAttachments()->count());
        $this->assertSame('hasil-v2.pdf', $audit->resultAttachments()->first()->file_name);
        $replaced = $audit->statusHistories()->where('event', SupplierAuditStatusHistory::EVENT_RESULT_REPLACED)->first();
        $this->assertSame('Salah bobot', $replaced->notes);
    }

    public function test_upload_rejected_before_submit_and_for_invalid_files(): void
    {
        $draft = $this->assign($this->supplier());

        $this->actingAs($this->purchasing)->post(route('purchasing.supplier-audits.result', $draft), [
            'result_file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        [, $audit] = $this->submitted();
        $this->actingAs($this->purchasing)->post(route('purchasing.supplier-audits.result', $audit), [
            'result_file' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('result_file');
        $this->actingAs($this->purchasing)->post(route('purchasing.supplier-audits.result', $audit), [
            'result_file' => UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('result_file');

        $this->assertSame(SupplierAudit::STATUS_SUBMITTED, $audit->fresh()->status);
        $this->assertSame(0, Attachment::where('attachable_type', SupplierAudit::class)->count());
    }

    public function test_stored_file_is_removed_when_publication_fails(): void
    {
        [, $audit] = $this->submitted();
        app(SupplierAuditReviewService::class)->cancel($this->purchasing, $audit, 'Batal');

        try {
            app(SupplierAuditReviewService::class)->publishResult($this->purchasing, $audit->fresh(), $this->pdf());
            $this->fail('Publishing a cancelled audit must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));
    }

    public function test_supplier_downloads_only_latest_result(): void
    {
        [$supplier, $audit] = $this->submitted();
        $service = app(SupplierAuditReviewService::class);
        $service->publishResult($this->purchasing, $audit, $this->pdf('v1.pdf'));
        $service->publishResult($this->purchasing, $audit->fresh(), $this->pdf('v2.pdf'), 'Revisi nilai');

        [$latest, $old] = $audit->resultAttachments()->get()->all();

        $this->actingAs($supplier)->get(route('attachments.show', $latest))->assertOk();
        $this->actingAs($supplier)->get(route('attachments.show', $old))->assertForbidden();

        $this->actingAs($this->supplier())->get(route('attachments.show', $latest))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance']))->get(route('attachments.show', $latest))->assertForbidden();

        $this->actingAs($this->purchasing)->get(route('attachments.show', $old))->assertOk();
        $this->actingAs($this->purchasing)->get(route('attachments.show', $latest))->assertOk();

        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.show', $audit))
            ->assertOk()
            ->assertSee(route('attachments.show', $latest), false)
            ->assertDontSee(route('attachments.show', $old), false);
    }

    public function test_purchasing_show_page_renders_actions_by_status(): void
    {
        [, $audit] = $this->submitted();

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.show', $audit))
            ->assertOk()
            ->assertSee(route('purchasing.export.supplier-audits.detail', $audit), false)
            ->assertSee(route('purchasing.supplier-audits.request-revision', $audit), false)
            ->assertSee(route('purchasing.supplier-audits.result', $audit), false)
            ->assertSee(__('supplier_audit.labels.summary'));
    }
}
