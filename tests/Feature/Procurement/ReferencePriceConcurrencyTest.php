<?php

namespace Tests\Feature\Procurement;

use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Mission 06B: two overlapping inserts committed concurrently are serialized
 * by the material_masters row lock; exactly one is refused.
 */
class ReferencePriceConcurrencyTest extends TestCase
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

    public function test_two_concurrent_overlapping_inserts_yield_one_refusal(): void
    {
        $this->setUpProcurementActors();
        $material = MaterialMaster::create([
            'material_code' => 'M06B-RACE', 'normalized_code' => 'M06B-RACE', 'raw_category' => 'Fixture',
            'hs_category' => 'alloy_steel', 'density_profile' => 'steel', 'manufacturer_scope' => 'unknown', 'is_active' => true,
        ]);
        $start = (string) (microtime(true) + 2);
        $processes = array_map(fn (array $range) => new Process([
            PHP_BINARY, base_path('tests/Support/reference-price-overlap-worker.php'),
            (string) $material->id, (string) $this->purchasing->id, $range[0], $range[1], $start,
        ], base_path(), ['APP_ENV' => 'testing']), [['100', '150'], ['150', '200']]);

        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $this->assertEqualsCanonicalizing(['created', 'overlap'], array_map(fn (Process $process) => trim($process->getOutput()), $processes));
        $this->assertSame(1, MaterialReferencePrice::count());
    }
}
