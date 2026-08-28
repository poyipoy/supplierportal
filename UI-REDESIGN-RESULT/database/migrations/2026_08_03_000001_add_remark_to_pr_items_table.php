<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pr_items', 'remark')) {
            Schema::table('pr_items', function (Blueprint $table) {
                $table->text('remark')->nullable()->after('weight_needed');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pr_items', 'remark')) {
            Schema::table('pr_items', function (Blueprint $table) {
                $table->dropColumn('remark');
            });
        }
    }
};
