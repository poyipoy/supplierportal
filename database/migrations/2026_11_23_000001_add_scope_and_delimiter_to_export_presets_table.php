<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('export_presets')) {
            return;
        }

        if (! Schema::hasColumn('export_presets', 'scope')) {
            Schema::table('export_presets', function (Blueprint $table): void {
                $table->string('scope', 16)->default('personal')->after('export_key');
            });
        }

        if (! Schema::hasColumn('export_presets', 'csv_delimiter')) {
            Schema::table('export_presets', function (Blueprint $table): void {
                $table->string('csv_delimiter', 4)->default(',')->after('format');
            });
        }

        if (! Schema::hasIndex('export_presets', 'export_presets_export_key_scope_index')) {
            Schema::table('export_presets', function (Blueprint $table): void {
                $table->index(['export_key', 'scope'], 'export_presets_export_key_scope_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('export_presets')) {
            return;
        }

        if (Schema::hasIndex('export_presets', 'export_presets_export_key_scope_index')) {
            Schema::table('export_presets', function (Blueprint $table): void {
                $table->dropIndex('export_presets_export_key_scope_index');
            });
        }

        $columns = array_values(array_filter(
            ['scope', 'csv_delimiter'],
            fn (string $column): bool => Schema::hasColumn('export_presets', $column),
        ));

        if ($columns !== []) {
            Schema::table('export_presets', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
