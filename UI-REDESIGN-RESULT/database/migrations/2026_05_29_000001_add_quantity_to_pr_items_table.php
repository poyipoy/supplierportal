<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pr_items', 'quantity')) {
            Schema::table('pr_items', function (Blueprint $table) {
                $table->unsignedInteger('quantity')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pr_items', 'quantity')) {
            Schema::table('pr_items', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }
};
