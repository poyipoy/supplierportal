<?php

namespace Tests\Feature\LocalInvoice;

use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalImportRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->actor = User::factory()->create(['role' => 'finance']);
        $this->supplier = User::factory()->create();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Recheck Vendor',
            'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
    }

    public function test_missing_po_can_be_repaired_and_same_private_file_rechecked_without_master_writes(): void
    {
        [$import, $oldToken] = $this->preview();
        $expiry = $import->expires_at->toIso8601String();
        $attachment = $import->attachments()->sole();
        $oldOwner = $import->owner_job_key;
        $this->assertSame('VALIDATION_FAILED', $import->status);
        $this->parentPo();
        $this->travel(10)->minutes();
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), ['expected_attempt' => $import->attempt])
            ->assertStatus(202)->assertJsonPath('status', 'QUEUED')->assertJsonPath('status_url', route('finance.local-procurement.imports.status', $import));
        $import->refresh();
        $this->assertNotSame($oldOwner, $import->owner_job_key);
        $this->assertSame($expiry, $import->expires_at->toIso8601String());
        $this->assertSame(1, $import->attachments()->count());
        $this->assertSame($attachment->file_path, $import->attachments()->sole()->file_path);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'gr_created')->count());
        Queue::assertPushed(ProcessLocalProcurementImport::class, fn ($job) => $job->runKey === $import->owner_job_key && $job->stage === 'preview');
        app(LocalProcurementImportService::class)->preview($import->id, $import->owner_job_key);
        $import->refresh();
        $this->assertSame('READY', $import->status);
        $this->assertSame(2, $import->attempt);
        $this->assertSame($expiry, $import->expires_at->toIso8601String());
        $this->assertDatabaseCount('local_goods_receipts', 0);
        $payload = $this->getJson(route('finance.local-procurement.imports.status', $import))->assertOk();
        $payload->assertJsonPath('validation_attempt', 2)->assertJsonPath('can_revalidate', true)
            ->assertJsonPath('revalidate_url', $this->revalidateUrl($import));
        $this->assertNotSame($oldToken, $payload->json('token'));
        $this->postJson(route('finance.local-procurement.import.gr.confirm'), ['token' => $oldToken])->assertStatus(419);
        $this->postJson(route('finance.local-procurement.import.gr.confirm'), ['token' => $payload->json('token')])->assertStatus(202);
        app(LocalProcurementImportService::class)->commit($import->id);
        $this->assertSame('COMPLETED', $import->fresh()->status);
        $this->assertDatabaseCount('local_goods_receipts', 1);
    }

    public function test_initial_preview_does_not_extend_expiry_from_upload_time(): void
    {
        [$import] = app(LocalProcurementImportService::class)->start($this->actor, $this->workbook(), 'GR');
        $expiry = $import->expires_at->toIso8601String();
        $this->travel(20)->minutes();
        app(LocalProcurementImportService::class)->preview($import->id);
        $this->assertSame($expiry, $import->fresh()->expires_at->toIso8601String());
    }

    public static function invalidAttempt(): array
    {
        return [[[]], [['expected_attempt' => -1]], [['expected_attempt' => 'bad']]];
    }

    #[DataProvider('invalidAttempt')]
    public function test_attempt_is_validated(array $input): void
    {
        [$import] = $this->preview();
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), $input)
            ->assertUnprocessable()->assertJsonValidationErrors('expected_attempt');
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
    }

    public function test_stale_attempt_and_double_click_do_not_replace_new_job(): void
    {
        [$import] = $this->preview();
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), ['expected_attempt' => 0])->assertStatus(409);
        $input = ['expected_attempt' => $import->attempt];
        $this->postJson($this->revalidateUrl($import), $input)->assertStatus(202);
        $owner = $import->fresh()->owner_job_key;
        $this->postJson($this->revalidateUrl($import), $input)->assertStatus(409);
        $this->assertSame($owner, $import->fresh()->owner_job_key);
    }

    public static function forbiddenStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['QUEUED', 'READING', 'VALIDATING', 'IMPORTING', 'COMPLETED', 'CANCELLED', 'EXPIRED']);
    }

    #[DataProvider('forbiddenStatuses')]
    public function test_active_or_final_import_cannot_be_revalidated(string $status): void
    {
        [$import] = $this->preview();
        $import->update(['status' => $status]);
        $owner = $import->owner_job_key;
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), ['expected_attempt' => $import->attempt])->assertStatus(409);
        $this->assertSame($owner, $import->fresh()->owner_job_key);
    }

    public function test_expired_missing_cleaned_and_tampered_files_are_not_revalidated(): void
    {
        foreach (['expired', 'missing', 'cleaned', 'tampered'] as $condition) {
            [$import] = $this->preview();
            $path = $import->attachments()->sole()->file_path;
            match ($condition) {
                'expired' => $import->update(['expires_at' => now()->subMinute()]),
                'missing' => Storage::disk('private')->delete($path),
                'cleaned' => $import->update(['cleaned_at' => now()]),
                'tampered' => Storage::disk('private')->put($path, 'changed bytes'),
            };
            $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), ['expected_attempt' => $import->attempt])
                ->assertStatus($condition === 'expired' ? 419 : 422);
            if ($condition === 'expired') {
                $this->getJson(route('finance.local-procurement.imports.status', $import))->assertOk()
                    ->assertJsonPath('status', 'EXPIRED')->assertJsonPath('can_revalidate', false);
                $this->getJson(route('finance.local-procurement.imports.status', $import).'?progress_only=1')->assertOk()->assertJsonPath('status', 'EXPIRED');
            }
            $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        }
    }

    public function test_revalidation_obeys_shared_capacity(): void
    {
        [$failed] = $this->preview();
        $service = app(LocalProcurementImportService::class);
        foreach ([1, 2] as $i) {
            $service->start($this->actor, $this->workbook(), 'GR');
        }
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($failed), ['expected_attempt' => $failed->attempt])
            ->assertUnprocessable()->assertJsonValidationErrors('import_file');
        $this->assertSame('VALIDATION_FAILED', $failed->fresh()->status);
    }

    public function test_ready_revalidation_reuses_its_slot_even_at_capacity(): void
    {
        $this->parentPo();
        [$ready] = $this->preview();
        app(LocalProcurementImportService::class)->start($this->actor, $this->workbook(), 'GR');
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($ready), ['expected_attempt' => $ready->attempt])->assertStatus(202);
        $this->assertSame(2, LocalProcurementImport::whereIn('status', LocalProcurementImport::ACTIVE)->count());
    }

    public function test_failed_import_is_available_in_opt_in_list_without_changing_active_list_contract(): void
    {
        [$failed] = $this->preview();
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.index', ['active' => 1]))->assertExactJson([]);
        $this->getJson(route('finance.local-procurement.imports.index', ['active' => 1, 'include_recoverable' => 1]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $failed->hash)->assertJsonPath('0.can_revalidate', true);
        $this->getJson(route('finance.local-procurement.imports.status', $failed).'?progress_only=1')
            ->assertOk()->assertJsonPath('validation_attempt', 1)->assertJsonMissingPath('preview');
    }

    public function test_other_owner_and_ineligible_roles_cannot_revalidate(): void
    {
        [$import] = $this->preview();
        $other = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($other)->postJson($this->revalidateUrl($import, 'purchasing'), ['expected_attempt' => $import->attempt])->assertForbidden();
        $this->actingAs($this->supplier)->postJson($this->revalidateUrl($import), ['expected_attempt' => $import->attempt])->assertForbidden();
        $this->actor->update(['is_active' => false]);
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), ['expected_attempt' => $import->attempt])->assertUnauthorized();
    }

    public function test_service_rechecks_stale_actor_permissions(): void
    {
        [$import] = $this->preview();
        $stale = $this->actor->fresh();
        $this->actor->update(['role' => 'supplier']);
        $this->expectException(AuthorizationException::class);
        app(LocalProcurementImportService::class)->revalidate($stale, $import, (int) $import->attempt);
    }

    public function test_legacy_ready_token_remains_valid_until_first_revalidation(): void
    {
        $this->parentPo();
        [$import] = $this->preview();
        $legacy = substr(hash_hmac('sha256', $import->id.':'.$import->user_id.':'.$import->checksum, config('app.key')), 0, 40);
        $import->update(['token_hash' => hash('sha256', $legacy)]);
        $this->assertSame($legacy, app(LocalProcurementImportService::class)->tokenFor($import->fresh()));
        $this->actingAs($this->actor)->postJson($this->revalidateUrl($import), ['expected_attempt' => $import->attempt])->assertStatus(202);
        app(LocalProcurementImportService::class)->preview($import->id);
        $this->postJson(route('finance.local-procurement.import.gr.confirm'), ['token' => $legacy])->assertStatus(419);
    }

    public static function ownerRoles(): array
    {
        return [['finance', 'finance'], ['admin', 'finance'], ['purchasing', 'purchasing']];
    }

    #[DataProvider('ownerRoles')]
    public function test_failed_process_can_be_rechecked_by_its_current_authorized_owner(string $role, string $prefix): void
    {
        [$import] = $this->preview();
        $import->update(['status' => 'FAILED']);
        $this->actor->update(['role' => $role]);
        $this->parentPo();
        $this->actingAs($this->actor->fresh())->postJson($this->revalidateUrl($import, $prefix), ['expected_attempt' => $import->attempt])->assertStatus(202);
        app(LocalProcurementImportService::class)->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status);
        $this->assertDatabaseCount('local_goods_receipts', 0);
    }

    public function test_database_queue_handoff_and_revalidation_state_roll_back_together_on_failure(): void
    {
        [$import] = $this->preview();
        $owner = $import->owner_job_key;
        $hash = $import->token_hash;
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql', 'queue.connections.database.after_commit' => false]);
        $this->useDatabaseQueue();
        Event::listen(JobQueued::class, function () {
            throw new \RuntimeException('Queue handoff fixture failure');
        });
        try {
            app(LocalProcurementImportService::class)->revalidate($this->actor, $import, (int) $import->attempt);
            $this->fail('Expected queue failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Queue handoff fixture failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertSame($owner, $import->fresh()->owner_job_key);
        $this->assertSame($hash, $import->fresh()->token_hash);
    }

    public function test_database_queue_revalidation_handoff_contains_one_bounded_preview_job(): void
    {
        [$import] = $this->preview();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql', 'queue.connections.database.after_commit' => false]);
        $this->useDatabaseQueue();
        app(LocalProcurementImportService::class)->revalidate($this->actor, $import, (int) $import->attempt);
        $job = DB::table('jobs')->sole();
        $this->assertSame('imports', $job->queue);
        $this->assertLessThan(10240, strlen($job->payload));
        $this->assertSame('QUEUED', $import->fresh()->status);
        $this->assertStringNotContainsString('imports/uploads', $job->payload);
    }

    private function preview(): array
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook(), 'GR');
        $service->preview($import->id);

        return [$import->fresh(), $token];
    }

    private function useDatabaseQueue(): void
    {
        $manager = new QueueManager($this->app);
        (new QueueServiceProvider($this->app))->registerConnectors($manager);
        Queue::swap($manager);
    }

    private function parentPo(): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create(['po_number' => 'RECHECK-PARENT', 'supplier_id' => $this->supplier->id,
            'po_date' => '2026-10-01', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN']);
    }

    private function revalidateUrl(LocalProcurementImport $import, string $prefix = 'finance'): string
    {
        return route($prefix.'.local-procurement.imports.revalidate', $import);
    }

    private function workbook(): UploadedFile
    {
        $path = Storage::disk('private')->path('revalidate-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
        $row = array_fill(0, 21, null);
        $row[9] = 'RECHECK-GR';
        $row[11] = 'RECHECK-PARENT';
        $row[15] = '1';
        $row[16] = 'pcs';
        $row[19] = '2026-10-01';
        $writer->addRow(Row::fromValues($row));
        $writer->close();

        return new UploadedFile($path, 'recheck.xlsx', null, null, true);
    }
}
