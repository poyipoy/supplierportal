<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('remember_token');
            }

            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }

            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }

            if (! Schema::hasColumn('users', 'two_factor_last_used_step')) {
                $table->unsignedBigInteger('two_factor_last_used_step')->nullable()->after('two_factor_confirmed_at');
            }

            if (! Schema::hasColumn('users', 'auth_session_version')) {
                $table->unsignedInteger('auth_session_version')->default(1)->after('two_factor_last_used_step');
            }
        });
    }

    public function down(): void
    {
        $columns = collect([
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'two_factor_last_used_step',
            'auth_session_version',
        ])->filter(fn (string $column): bool => Schema::hasColumn('users', $column))->all();

        if ($columns !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
