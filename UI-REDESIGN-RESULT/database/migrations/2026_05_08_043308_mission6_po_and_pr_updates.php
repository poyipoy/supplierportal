<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add pr_number to purchase_requirements
        Schema::table('purchase_requirements', function (Blueprint $table) {
            $table->string('pr_number')->nullable()->unique()->after('id');
        });

        // 2. Add notes column to purchase_orders
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('actual_arrival');
        });

        // 3. Update purchase_orders status enum to include waiting_qc
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('active','overdue','waiting_qc','completed','cancelled') DEFAULT 'active'");

        // 4. Update po_documents status enum per user requirements
        DB::statement("ALTER TABLE po_documents MODIFY COLUMN status ENUM('pending','received','verified','issued','processing','done') DEFAULT 'pending'");

        // 5. Update quotations status enum to include accepted
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','submitted','accepted','rejected') DEFAULT 'draft'");
    }

    public function down(): void
    {
        Schema::table('purchase_requirements', function (Blueprint $table) {
            $table->dropColumn('pr_number');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('draft','issued','shipped','arrived','completed','cancelled') DEFAULT 'draft'");
        DB::statement("ALTER TABLE po_documents MODIFY COLUMN status ENUM('pending','uploaded','verified') DEFAULT 'pending'");
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','submitted','rejected') DEFAULT 'draft'");
    }
};
