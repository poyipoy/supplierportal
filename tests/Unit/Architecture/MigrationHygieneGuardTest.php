<?php

namespace Tests\Unit\Architecture;

use Tests\TestCase;

class MigrationHygieneGuardTest extends TestCase
{
    /**
     * Historical duplicate timestamp prefixes that are already recorded in the
     * production migrations table and therefore must not be renamed.
     *
     * @var array<string, int>
     */
    private const HISTORICAL_DUPLICATE_PREFIXES = [
        '2026_09_08_000001' => 2,
        '2026_09_08_000002' => 2,
        '2026_10_07_000001' => 2,
        '2026_10_07_000002' => 2,
    ];

    public function test_no_new_duplicate_migration_timestamp_prefixes_exist(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        $byPrefix = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $basename, $matches) === 1) {
                $byPrefix[$matches[1]][] = $basename;
            }
        }

        $violations = [];
        foreach ($byPrefix as $prefix => $names) {
            $allowedCount = self::HISTORICAL_DUPLICATE_PREFIXES[$prefix] ?? 1;
            if (count($names) > $allowedCount) {
                $violations[$prefix] = $names;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Duplicate migration timestamp prefixes detected outside historical allow-list: '.json_encode($violations, JSON_PRETTY_PRINT)
        );
    }

    public function test_no_loose_sql_files_exist_in_root_or_database_root(): void
    {
        $rootSql = glob(base_path('*.sql')) ?: [];
        $databaseRootSql = glob(database_path('*.sql')) ?: [];

        $this->assertSame([], $rootSql, 'Loose .sql files must not reside in repository root; archive them in database/sql/archive/.');
        $this->assertSame([], $databaseRootSql, 'Loose .sql files must not reside directly in database/; archive them in database/sql/archive/.');
    }
}
