<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('schema-migration')]
class GaClaimTypeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // DDL commits implicitly: keep this test outside RefreshDatabase's
        // transaction and never roll back unrelated historical migrations.
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
            throw new \RuntimeException('Migration verification requires adasi_portal_test.');
        }
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function data(string $type): array
    {
        $employee = Employee::create([
            'name' => 'Migration Employee', 'department' => 'GA', 'bank_name' => 'Mandiri',
            'account_number' => '123456', 'account_holder_name' => 'Migration Employee', 'is_active' => true,
        ]);

        return ['employee_id' => $employee->id, 'claim_type' => $type, 'claim_date' => '2026-10-07', 'amount' => '250000'];
    }

    public function test_migration_maps_legacy_values_and_constrains_enum_without_changing_status(): void
    {
        $this->assertContains(DB::getDriverName(), ['mysql', 'mariadb']);
        DB::statement("ALTER TABLE ga_claims MODIFY claim_type ENUM('Entertain Sales','UPD Sales','UPD GA','Reimburse/Claim') NOT NULL");
        $ga = User::factory()->create(['role' => 'ga']);
        $mapping = ['Entertain Sales' => 'Entertainment', 'UPD Sales' => 'Business Travel', 'UPD GA' => 'Business Travel', 'Reimburse/Claim' => 'Reimburse/Claim'];
        $ids = [];
        foreach ($mapping as $old => $canonical) {
            $claim = GaClaim::create([...$this->data($old), 'claim_number' => 'CLM-MAP-'.count($ids),
                'status' => GaClaim::STATUS_READY_TO_PAY, 'submitted_by' => $ga->id, 'submitted_at' => now(),
            ]);
            $ids[$claim->id] = $canonical;
        }
        $migration = require database_path('migrations/2026_10_07_000002_canonicalize_ga_claim_types.php');
        $migration->up();
        foreach ($ids as $id => $type) {
            $this->assertDatabaseHas('ga_claims', ['id' => $id, 'claim_type' => $type, 'status' => GaClaim::STATUS_READY_TO_PAY]);
        }
        $column = DB::selectOne("SHOW COLUMNS FROM ga_claims LIKE 'claim_type'");
        $this->assertSame("enum('Entertainment','Business Travel','Reimburse/Claim')", $column->Type);
        $migration->up();
        $this->assertSame(0, DB::table('ga_claims')->whereIn('claim_type', ['Entertain Sales', 'UPD Sales', 'UPD GA'])->count());
        try {
            $migration->down();
            $this->fail('Merged claim categories must not be guessed during rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('authoritative data restore', $e->getMessage());
        }
        DB::table('ga_claims')->whereIn('id', array_keys($ids))->delete();
    }
}
