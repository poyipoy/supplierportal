<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SupplierAuditMigrationPreflightTest extends TestCase
{
    public static function unsupportedVersions(): array
    {
        return [
            'old MySQL' => ['mysql', '8.0.15'],
            'MariaDB compatibility driver' => ['mysql', '10.11.14-MariaDB'],
            'MariaDB driver' => ['mariadb', '10.11.14-MariaDB'],
            'unrecognized server' => ['mysql', 'unknown'],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_migration_refuses_unsupported_servers_before_schema_changes(string $driver, string $version): void
    {
        DB::shouldReceive('selectOne')->once()->with('SELECT VERSION() AS version')->andReturn((object) ['version' => $version]);
        DB::shouldReceive('getDriverName')->once()->andReturn($driver);
        DB::shouldReceive('statement')->never();
        $migration = require database_path('migrations/2026_10_12_000003_guard_active_supplier_audit_uniqueness.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires MySQL 8.0.16 or later');
        $migration->up();
    }

    public function test_migration_reports_duplicate_suppliers_before_schema_changes(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) ['version' => '8.0.30']);
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('table')->with('supplier_audits')->once()->andReturn($query = \Mockery::mock());
        $query->shouldReceive('whereIn')->once()->andReturnSelf();
        $query->shouldReceive('select')->with('supplier_id')->once()->andReturnSelf();
        $query->shouldReceive('groupBy')->with('supplier_id')->once()->andReturnSelf();
        $query->shouldReceive('havingRaw')->with('COUNT(*) > 1')->once()->andReturnSelf();
        $query->shouldReceive('orderBy')->with('supplier_id')->once()->andReturnSelf();
        $query->shouldReceive('limit')->with(20)->once()->andReturnSelf();
        $query->shouldReceive('pluck')->with('supplier_id')->once()->andReturn(collect([12, 34]));
        DB::shouldReceive('statement')->never();
        $migration = require database_path('migrations/2026_10_12_000003_guard_active_supplier_audit_uniqueness.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supplier user IDs (first 20): 12, 34');
        $migration->up();
    }
}
