<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'status')) {
                $table->string('status', 32)->default('open')->after('supplier_user_id');
            }

            if (! Schema::hasColumn('conversations', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('status');
            }

            $table->index(['purchasing_user_id', 'status'], 'conv_purchasing_status_index');
            $table->index(['supplier_user_id', 'status'], 'conv_supplier_status_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'sender_id', 'read_at'], 'messages_read_receipt_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_read_receipt_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conv_purchasing_status_index');
            $table->dropIndex('conv_supplier_status_index');

            if (Schema::hasColumn('conversations', 'resolved_at')) {
                $table->dropColumn('resolved_at');
            }

            if (Schema::hasColumn('conversations', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
