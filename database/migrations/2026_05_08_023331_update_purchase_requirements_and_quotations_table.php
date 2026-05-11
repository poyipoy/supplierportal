<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix PR status Enum
        DB::statement("ALTER TABLE purchase_requirements MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'bidding', 'completed') DEFAULT 'draft'");

        // Add new columns to quotations
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->date('estimated_delivery')->nullable();
            $table->text('payment_terms')->nullable();
            $table->date('validity_period')->nullable();
            $table->text('general_notes')->nullable();
        });

        // Update quotations status Enum to match expected logic
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft', 'submitted', 'rejected') DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['exchange_rate_id']);
            $table->dropColumn([
                'exchange_rate_id',
                'estimated_delivery',
                'payment_terms',
                'validity_period',
                'general_notes'
            ]);
        });

        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft', 'submitted', 'accepted', 'rejected') DEFAULT 'draft'");
        DB::statement("ALTER TABLE purchase_requirements MODIFY COLUMN status ENUM('draft', 'published', 'closed') DEFAULT 'draft'");
    }
};
