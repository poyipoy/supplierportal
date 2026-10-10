<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen document_type enum to include the optional registration Company Profile
        DB::statement("ALTER TABLE supplier_master_documents MODIFY document_type ENUM('NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'SKD', 'COMPANY_PROFILE', 'OTHER') NOT NULL");
    }

    public function down(): void
    {
        // Narrowing the enum would silently corrupt stored Company Profile rows, so refuse instead.
        if (DB::table('supplier_master_documents')->where('document_type', 'COMPANY_PROFILE')->exists()) {
            throw new RuntimeException('Cannot roll back: supplier_master_documents contains COMPANY_PROFILE documents. Reclassify or remove them explicitly first.');
        }

        DB::statement("ALTER TABLE supplier_master_documents MODIFY document_type ENUM('NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'SKD', 'OTHER') NOT NULL");
    }
};
