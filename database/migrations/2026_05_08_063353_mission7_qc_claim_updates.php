<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update purchase_orders status enum
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('active','waiting_qc','completed','claim_needed','cancelled') DEFAULT 'active'");

        // 2. Add notes to qc_items
        Schema::table('qc_items', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('status');
        });

        // 3. Update material_claims table
        DB::statement("ALTER TABLE material_claims MODIFY COLUMN status ENUM('pending','responded','resolved','escalated') DEFAULT 'pending'");
        Schema::table('material_claims', function (Blueprint $table) {
            $table->text('description')->after('status')->nullable();
            $table->text('resolution_expected')->after('description')->nullable();
            $table->date('deadline')->after('resolution_expected')->nullable();
            $table->text('supplier_response')->after('deadline')->nullable();
            // Drop old notes column since description covers it, but to be safe, we just leave it or rename it.
            // Let's just drop notes and use description
            $table->dropColumn('notes');
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('active','waiting_qc','completed','cancelled') DEFAULT 'active'");

        Schema::table('qc_items', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        DB::statement("ALTER TABLE material_claims MODIFY COLUMN status ENUM('draft','submitted','in_review','accepted','rejected') DEFAULT 'draft'");
        Schema::table('material_claims', function (Blueprint $table) {
            $table->dropColumn(['description', 'resolution_expected', 'deadline', 'supplier_response']);
            $table->text('notes')->nullable();
        });
    }
};
