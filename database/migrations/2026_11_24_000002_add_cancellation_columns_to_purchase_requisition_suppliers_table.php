<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisition_suppliers', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_requisition_suppliers', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('invited_at');
            }
            if (! Schema::hasColumn('purchase_requisition_suppliers', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_requisition_suppliers', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
        });

        Schema::table('purchase_requisition_suppliers', function (Blueprint $table): void {
            $table->index(['supplier_id', 'cancelled_at'], 'pr_suppliers_supplier_cancelled_idx');
        });
    }

    public function down(): void
    {
        $cancelledCount = DB::table('purchase_requisition_suppliers')
            ->whereNotNull('cancelled_at')
            ->count();

        if ($cancelledCount > 0) {
            throw new RuntimeException("Cannot roll back cancelled supplier invitations: {$cancelledCount} cancelled invitation row(s) exist.");
        }

        Schema::table('purchase_requisition_suppliers', function (Blueprint $table): void {
            $table->dropIndex('pr_suppliers_supplier_cancelled_idx');
            if (Schema::hasColumn('purchase_requisition_suppliers', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
            if (Schema::hasColumn('purchase_requisition_suppliers', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            if (Schema::hasColumn('purchase_requisition_suppliers', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
