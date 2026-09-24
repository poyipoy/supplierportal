<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_master_documents', function (Blueprint $table) {
            $table->foreignId('supplier_bank_account_id')
                ->nullable()
                ->after('uploaded_by')
                ->constrained('supplier_bank_accounts')
                ->nullOnDelete();
        });

        // Widen document_type enum to include SKD
        DB::statement("ALTER TABLE supplier_master_documents MODIFY document_type ENUM('NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'SKD', 'OTHER') NOT NULL");
    }

    public function down(): void
    {
        // Revert enum (check no SKD documents exist first)
        DB::table('supplier_master_documents')->where('document_type', 'SKD')->update(['document_type' => 'OTHER']);
        DB::statement("ALTER TABLE supplier_master_documents MODIFY document_type ENUM('NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'OTHER') NOT NULL");

        Schema::table('supplier_master_documents', function (Blueprint $table) {
            $table->dropForeign(['supplier_bank_account_id']);
            $table->dropColumn('supplier_bank_account_id');
        });
    }
};
