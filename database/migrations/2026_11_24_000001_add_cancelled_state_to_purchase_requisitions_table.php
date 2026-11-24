<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_requisitions MODIFY COLUMN status ENUM('draft', 'submitted', 'rejected', 'bidding', 'completed', 'cancelled') NOT NULL DEFAULT 'draft'");

        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_requisitions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('purchase_requisitions', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_requisitions', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
        });
    }

    public function down(): void
    {
        $cancelledCount = DB::table('purchase_requisitions')
            ->where('status', 'cancelled')
            ->count();

        if ($cancelledCount > 0) {
            throw new RuntimeException("Cannot roll back cancelled PR status: {$cancelledCount} cancelled purchase requisition row(s) exist.");
        }

        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_requisitions', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
            if (Schema::hasColumn('purchase_requisitions', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            if (Schema::hasColumn('purchase_requisitions', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });

        DB::statement("ALTER TABLE purchase_requisitions MODIFY COLUMN status ENUM('draft', 'submitted', 'rejected', 'bidding', 'completed') NOT NULL DEFAULT 'draft'");
    }
};
