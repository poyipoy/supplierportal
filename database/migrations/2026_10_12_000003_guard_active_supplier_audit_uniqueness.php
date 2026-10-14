<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $version = (string) (DB::selectOne('SELECT VERSION() AS version')->version ?? '');
        if (DB::getDriverName() !== 'mysql' || str_contains(strtolower($version), 'mariadb')
            || ! preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)
            || version_compare($matches[1], '8.0.16', '<')) {
            throw new RuntimeException('Active supplier audit uniqueness requires MySQL 8.0.16 or later. Verify the target server before deploying this migration.');
        }

        $duplicates = DB::table('supplier_audits')
            ->whereIn('status', ['ASSIGNED', 'DRAFT', 'SUBMITTED', 'REVISION_REQUESTED'])
            ->select('supplier_id')->groupBy('supplier_id')->havingRaw('COUNT(*) > 1')
            ->orderBy('supplier_id')->limit(20)->pluck('supplier_id');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Resolve multiple active supplier audits before retrying this migration. Supplier user IDs (first 20): '.$duplicates->implode(', ').'. Preserve the audit history and close duplicates through the review workflow.');
        }

        // One ALTER keeps the generated column and its index atomic on MySQL 8.
        DB::statement("ALTER TABLE supplier_audits
            ADD COLUMN active_supplier_id BIGINT UNSIGNED GENERATED ALWAYS AS
                (CASE WHEN status IN ('ASSIGNED', 'DRAFT', 'SUBMITTED', 'REVISION_REQUESTED') THEN supplier_id ELSE NULL END) VIRTUAL,
            ADD UNIQUE INDEX supplier_audits_active_supplier_unique (active_supplier_id)");
    }

    public function down(): void
    {
        Schema::table('supplier_audits', function (Blueprint $table): void {
            $table->dropUnique('supplier_audits_active_supplier_unique');
            $table->dropColumn('active_supplier_id');
        });
    }
};
