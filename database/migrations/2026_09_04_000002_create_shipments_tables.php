<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE document_sequences MODIFY COLUMN type ENUM('PR', 'PO', 'SHP') NOT NULL");
        }

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('shipment_number')->unique();
            $table->foreignId('supplier_id')->constrained('users');
            $table->enum('status', ['draft', 'submitted', 'arrived', 'cancelled'])->default('draft');
            $table->date('shipment_date')->nullable();
            $table->date('estimated_arrival_date')->nullable();
            $table->date('actual_arrival_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained('quotation_items')->cascadeOnDelete();
            $table->foreignId('pr_item_award_id')->nullable()->constrained('pr_item_awards')->nullOnDelete();
            $table->decimal('shipped_quantity', 12, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'purchase_order_id']);
            $table->index(['purchase_order_id', 'quotation_item_id']);
            $table->unique(['shipment_id', 'purchase_order_id', 'quotation_item_id'], 'shipment_items_unique_item');
        });

        Schema::create('shipment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->enum('doc_type', ['invoice', 'packing_list', 'bl', 'form_e']);
            $table->enum('status', ['pending', 'received', 'verified', 'issued', 'processing', 'done'])->default('pending');
            $table->string('document_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'doc_type']);
        });

        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->foreignId('shipment_id')->nullable()->after('po_id')->constrained('shipments')->nullOnDelete();
        });

        Schema::table('qc_items', function (Blueprint $table): void {
            $table->foreignId('shipment_item_id')->nullable()->after('pr_item_id')->constrained('shipment_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qc_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipment_item_id');
        });

        Schema::table('qc_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipment_id');
        });

        Schema::dropIfExists('shipment_documents');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');

        if (DB::getDriverName() === 'mysql') {
            DB::table('document_sequences')->where('type', 'SHP')->delete();
            DB::statement("ALTER TABLE document_sequences MODIFY COLUMN type ENUM('PR', 'PO') NOT NULL");
        }
    }
};
