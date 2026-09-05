<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Enforces the hard data invariant: ONE QUOTATION -> MAXIMUM ONE PURCHASE ORDER.
     */
    public function up(): void
    {
        // 1. Preflight check: verify no duplicate quotation_id exists before adding unique constraint
        $duplicates = DB::table('po_quotations')
            ->select('quotation_id', DB::raw('count(*) as total'))
            ->groupBy('quotation_id')
            ->having('total', '>', 1)
            ->exists();

        if ($duplicates) {
            throw new \RuntimeException(
                'Cannot add unique constraint to po_quotations: duplicate quotation_id records already exist in the database. Manual reconciliation required.'
            );
        }

        Schema::table('po_quotations', function (Blueprint $table): void {
            $table->unique('quotation_id', 'po_quotations_quotation_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('po_quotations', function (Blueprint $table): void {
            $table->dropUnique('po_quotations_quotation_id_unique');
        });
    }
};
