<?php

namespace Tests\Feature\Procurement;

use App\Models\SupplierGoodsLocation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PreshipmentGoodsLocationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use ProcurementFixtures;

    protected $exceptTables = ['local_po_document_capacity'];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_set_default_location_leaves_exactly_one_default_row(): void
    {
        $locA = SupplierGoodsLocation::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Factory Alpha',
            'address' => '1 Alpha Rd',
            'city' => 'Shanghai',
            'country' => 'CN',
            'is_default' => false,
        ]);
        $locB = SupplierGoodsLocation::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Factory Beta',
            'address' => '2 Beta Rd',
            'city' => 'Wuxi',
            'country' => 'CN',
            'is_default' => false,
        ]);

        for ($round = 1; $round <= 3; $round++) {
            $start = (string) (microtime(true) + 1.0);
            $processes = [
                new Process(
                    [PHP_BINARY, base_path('tests/Support/goods-location-default-concurrency-worker.php'), (string) $locA->id, $start],
                    base_path(),
                    ['APP_ENV' => 'testing']
                ),
                new Process(
                    [PHP_BINARY, base_path('tests/Support/goods-location-default-concurrency-worker.php'), (string) $locB->id, $start],
                    base_path(),
                    ['APP_ENV' => 'testing']
                ),
            ];

            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertSame('ok', trim($process->getOutput()));
            }

            $defaultCount = SupplierGoodsLocation::forSupplier($this->supplier->id)
                ->where('is_default', true)
                ->count();
            $this->assertSame(1, $defaultCount, "Round {$round} must leave exactly one default location.");
        }
    }
}
