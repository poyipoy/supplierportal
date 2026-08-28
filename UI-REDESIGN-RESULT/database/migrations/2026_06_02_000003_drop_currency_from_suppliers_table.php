<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('suppliers', 'currency')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('suppliers', 'currency')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->enum('currency', ['USD', 'JPY', 'IDR', 'CNY'])
                    ->default('USD')
                    ->after('category');
            });
        }
    }
};
