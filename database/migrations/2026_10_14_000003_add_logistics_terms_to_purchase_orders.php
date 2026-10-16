<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO snapshot of the participating PRs' Port of Discharge and Incoterms (Q1,
 * Q7). Written once at PO generation (Mission 02); NULL for every PO created
 * before that release.
 */
return new class extends Migration
{
    private const TABLE = 'purchase_orders';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('port_of_discharge', 30)->nullable()->after('notes');
            $table->string('incoterm', 10)->nullable()->after('port_of_discharge');
        });

        if ($this->supportsCheckConstraints()) {
            // Binary comparison: the default *_ci collation would otherwise accept
            // non-canonical case such as 'tanjung_priok'.
            DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_port_of_discharge_allowed CHECK (CAST(port_of_discharge AS BINARY) IN ('TANJUNG_PRIOK', 'TANJUNG_PERAK'))");
            DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_incoterm_allowed CHECK (CAST(incoterm AS BINARY) IN ('CFR', 'CIF', 'FOB'))");
        }
    }

    public function down(): void
    {
        // Soft-deleted POs keep their snapshot, so they count as well.
        $withLogistics = DB::table(self::TABLE)
            ->where(fn ($query) => $query->whereNotNull('port_of_discharge')->orWhereNotNull('incoterm'))
            ->count();

        if ($withLogistics > 0) {
            throw new RuntimeException("Cannot roll back PO logistics snapshot: {$withLogistics} purchase order(s) carry Port of Discharge or Incoterms.");
        }

        $this->dropCheck('purchase_orders_port_of_discharge_allowed');
        $this->dropCheck('purchase_orders_incoterm_allowed');

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(['port_of_discharge', 'incoterm']);
        });
    }

    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function dropCheck(string $name): void
    {
        if (! $this->supportsCheckConstraints()) {
            return;
        }

        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();

        if ($exists) {
            DB::statement('ALTER TABLE '.self::TABLE." DROP CHECK {$name}");
        }
    }
};
