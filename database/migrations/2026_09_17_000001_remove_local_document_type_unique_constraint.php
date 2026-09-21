<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('local_invoice_documents', function (Blueprint $table) {
            $table->dropUnique('local_document_type_unique');
            $table->dropUnique(['file_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_invoice_documents', function (Blueprint $table) {
            $table->unique(['local_invoice_revision_id', 'document_type'], 'local_document_type_unique');
            $table->unique(['file_path']);
        });
    }
};
