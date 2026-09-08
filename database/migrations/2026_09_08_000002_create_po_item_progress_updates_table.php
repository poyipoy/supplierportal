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
        Schema::create('po_item_progress_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_item_award_id')->constrained('pr_item_awards');
            $table->string('status', 32);
            $table->unsignedInteger('supplier_controlled_qty_snapshot');
            $table->date('estimated_ready_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['pr_item_award_id', 'created_at', 'id'],
                'po_item_progress_award_history_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('po_item_progress_updates');
    }
};
