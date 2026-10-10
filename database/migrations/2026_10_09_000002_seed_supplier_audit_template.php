<?php

use Database\Seeders\SupplierAuditTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Mengisi template checklist Supplier Audit v1 otomatis saat migrate,
 * sehingga tidak perlu langkah seeder manual per environment.
 * Seeder idempoten per [code, version]; aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SupplierAuditTemplateSeeder)->run();
    }

    public function down(): void
    {
        // Sengaja kosong: template bisa sudah dirujuk audit (FK restrict).
        // Tabelnya dihapus oleh down() migrasi 2026_10_09_000001.
    }
};
