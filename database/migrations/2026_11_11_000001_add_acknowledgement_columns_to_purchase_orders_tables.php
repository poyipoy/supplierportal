<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('acknowledgement_status', 32)->default('PENDING')->after('status');
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledgement_status');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete()->after('acknowledged_at');
            $table->date('supplier_promised_date')->nullable()->after('estimated_arrival');
            $table->text('acknowledgement_notes')->nullable()->after('notes');
        });

        Schema::table('local_purchase_orders', function (Blueprint $table) {
            $table->string('acknowledgement_status', 32)->default('PENDING')->after('status');
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledgement_status');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete()->after('acknowledged_at');
            $table->date('supplier_promised_date')->nullable()->after('po_date');
            $table->text('acknowledgement_notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn([
                'acknowledgement_status',
                'acknowledged_at',
                'supplier_promised_date',
                'acknowledgement_notes',
            ]);
        });

        Schema::table('local_purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn([
                'acknowledgement_status',
                'acknowledged_at',
                'supplier_promised_date',
                'acknowledgement_notes',
            ]);
        });
    }
};
