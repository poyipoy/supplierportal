<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }

            if (! Schema::hasColumn('quotations', 'reviewed_by')) {
                $table->foreignId('reviewed_by')
                    ->nullable()
                    ->after('reviewed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('quotations', 'reviewer_notes')) {
                $table->text('reviewer_notes')->nullable()->after('reviewed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'reviewed_by')) {
                $table->dropForeign(['reviewed_by']);
            }

            $dropColumns = array_filter([
                Schema::hasColumn('quotations', 'reviewed_at') ? 'reviewed_at' : null,
                Schema::hasColumn('quotations', 'reviewed_by') ? 'reviewed_by' : null,
                Schema::hasColumn('quotations', 'reviewer_notes') ? 'reviewer_notes' : null,
            ]);

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
