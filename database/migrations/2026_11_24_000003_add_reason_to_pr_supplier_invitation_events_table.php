<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_supplier_invitation_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('pr_supplier_invitation_events', 'reason')) {
                $table->text('reason')->nullable()->after('event');
            }
        });
    }

    public function down(): void
    {
        $reasonCount = DB::table('pr_supplier_invitation_events')
            ->whereNotNull('reason')
            ->count();

        if ($reasonCount > 0) {
            throw new RuntimeException("Cannot roll back supplier invitation event reason column: {$reasonCount} audit row(s) contain a reason.");
        }

        Schema::table('pr_supplier_invitation_events', function (Blueprint $table): void {
            if (Schema::hasColumn('pr_supplier_invitation_events', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }
};
