<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireMysql();

        // Expand before writing canonical values. MySQL DDL commits implicitly,
        // so each phase remains safe to repeat after an interrupted deployment.
        DB::statement("ALTER TABLE ga_claims MODIFY claim_type ENUM('Entertain Sales','UPD Sales','UPD GA','Reimburse/Claim','Entertainment','Business Travel') NOT NULL");

        DB::transaction(function (): void {
            DB::table('ga_claims')->where('claim_type', 'Entertain Sales')->update(['claim_type' => 'Entertainment']);
            DB::table('ga_claims')->whereIn('claim_type', ['UPD Sales', 'UPD GA'])->update(['claim_type' => 'Business Travel']);
        });

        if (DB::table('ga_claims')->whereNotIn('claim_type', ['Entertainment', 'Business Travel', 'Reimburse/Claim'])->exists()) {
            throw new RuntimeException('GA claim type migration found a non-canonical value; enum contraction stopped.');
        }

        DB::statement("ALTER TABLE ga_claims MODIFY claim_type ENUM('Entertainment','Business Travel','Reimburse/Claim') NOT NULL");
    }

    public function down(): void
    {
        $this->requireMysql();

        // Business Travel merged two historical types. Never guess which legacy
        // category to restore, or silently recategorize new canonical claims.
        if (DB::table('ga_claims')->whereIn('claim_type', ['Entertainment', 'Business Travel'])->exists()) {
            throw new RuntimeException('Cannot reverse GA canonical categories without an authoritative data restore; Business Travel merged legacy categories.');
        }

        DB::statement("ALTER TABLE ga_claims MODIFY claim_type ENUM('Entertain Sales','UPD Sales','UPD GA','Reimburse/Claim') NOT NULL");
    }

    private function requireMysql(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('GA claim type migration requires the project MySQL/MariaDB database platform.');
        }
    }
};
