<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DOCUMENT_TABLES = ['attachments', 'local_invoice_documents', 'ga_claim_documents', 'supplier_master_documents'];

    public function up(): void
    {
        Schema::create('file_inspections', function (Blueprint $table) {
            $table->id();
            $table->string('profile', 50);
            $table->string('status', 20)->index();
            $table->string('policy_version', 50);
            $table->string('file_path')->unique();
            $table->unsignedBigInteger('file_size');
            $table->string('file_type', 150);
            $table->char('sha256', 64);
            $table->timestamp('inspected_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('file_security_cutovers', function (Blueprint $table) {
            $table->string('document_table')->primary();
            $table->unsignedBigInteger('legacy_max_id');
            $table->timestamp('created_at');
        });
        foreach (self::DOCUMENT_TABLES as $name) {
            // Snapshot existing identities once. Future NULL inspections cannot
            // become legacy merely because their file or timestamp exists.
            DB::table('file_security_cutovers')->insert(['document_table' => $name, 'legacy_max_id' => DB::table($name)->max('id') ?? 0, 'created_at' => now()]);
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('file_inspection_id')->nullable()->constrained('file_inspections')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('local_po_document_batches') || DB::table('file_inspections')->exists()) {
            throw new RuntimeException('Retain native inspection metadata while published documents or PO batches depend on it.');
        }
        foreach (self::DOCUMENT_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('file_inspection_id'));
        }
        Schema::dropIfExists('file_security_cutovers');
        Schema::dropIfExists('file_inspections');
    }
};
