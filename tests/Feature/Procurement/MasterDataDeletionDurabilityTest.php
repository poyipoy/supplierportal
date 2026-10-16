<?php

namespace Tests\Feature\Procurement;

use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\User;
use App\Services\Materials\MasterDataDeletionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Mission 06A durability (I-16) and concurrency (AC-35) with real commits:
 * refusal audits are visible from another connection, the FK backstop path is
 * audited, and a concurrent referencing insert can never leave an orphan.
 */
class MasterDataDeletionDurabilityTest extends TestCase
{
    use DatabaseTruncation;
    use ProcurementFixtures;

    // Retain the migration-owned singleton used to serialize PO ZIP admission.
    protected $exceptTables = ['local_po_document_capacity'];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    // Committed rows must not leak into later RefreshDatabase tests.
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_refusal_audits_are_committed_and_visible_from_another_connection(): void
    {
        $material = $this->material();
        $pr = $this->createRequisition();
        $pr->items[0]->forceFill(['material_master_id' => $material->id])->save();

        $this->assertFalse(app(MasterDataDeletionService::class)->delete($material, $this->admin)->deleted);

        $backstop = new class extends MasterDataDeletionService
        {
            protected function referenceCounts(Model $entity): array
            {
                return ['pr_items' => 0, 'aliases' => 0, 'reference_prices' => 0];
            }
        };
        $this->assertSame('fk_restrict', $backstop->delete($material, $this->admin)->reason);

        $observer = $this->observer();
        $rows = $observer->query("SELECT action, JSON_UNQUOTE(JSON_EXTRACT(`after`, '$.reason')) AS reason FROM master_data_audit_logs WHERE entity_id = {$material->id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([
            ['action' => 'delete_refused', 'reason' => 'referenced'],
            ['action' => 'delete_refused', 'reason' => 'fk_restrict'],
        ], $rows);
        $this->assertSame(1, (int) $observer->query("SELECT COUNT(*) FROM material_masters WHERE id = {$material->id}")->fetchColumn());
    }

    public function test_concurrent_referencing_insert_and_delete_have_exactly_one_outcome(): void
    {
        $material = $this->material();
        $pr = $this->createRequisition();
        $start = (string) (microtime(true) + 2);

        $processes = [
            new Process([PHP_BINARY, base_path('tests/Support/master-data-delete-worker.php'), 'delete', (string) $material->id, (string) $this->admin->id, $start], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, base_path('tests/Support/master-data-delete-worker.php'), 'insert', (string) $pr->id, (string) $material->id, $start], base_path(), ['APP_ENV' => 'testing']),
        ];
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        [$delete, $insert] = array_map(fn (Process $process) => trim($process->getOutput()), $processes);

        $this->assertContains([$delete, $insert], [['deleted', 'insert_failed'], ['refused', 'inserted']]);
        $exists = MaterialMaster::whereKey($material->id)->exists();
        $references = DB::table('pr_items')->where('material_master_id', $material->id)->count();
        $this->assertSame($delete === 'refused', $exists);
        $this->assertSame($exists ? 1 : 0, $references, 'No PR item may reference a deleted material.');
        $this->assertSame(1, MasterDataAuditLog::where('entity_id', $material->id)
            ->where('action', $delete === 'deleted' ? 'deleted' : 'delete_refused')->count());
    }

    private function observer(): PDO
    {
        $config = config('database.connections.mysql');

        return new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
        );
    }

    private function material(): MaterialMaster
    {
        $code = 'M06A-DURABLE-'.strtoupper(uniqid());

        return MaterialMaster::create([
            'material_code' => $code, 'normalized_code' => $code, 'raw_category' => 'Fixture',
            'hs_category' => 'alloy_steel', 'density_profile' => 'steel', 'manufacturer_scope' => 'unknown', 'is_active' => true,
        ]);
    }
}
