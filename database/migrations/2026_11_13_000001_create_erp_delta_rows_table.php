<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_delta_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->restrictOnDelete();
            $table->string('doc_no', 100);
            $table->string('item_code', 100);
            $table->decimal('qty', 18, 4);
            $table->decimal('price', 18, 2);
            $table->string('status', 64);
            $table->char('row_hash', 16);
            $table->foreignId('last_synced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'doc_no', 'item_code'], 'erp_delta_rows_supplier_doc_item_unique');
            $table->index('doc_no', 'erp_delta_rows_doc_no_idx');
            $table->index(['supplier_id', 'row_hash'], 'erp_delta_rows_supplier_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_delta_rows');
    }
};
