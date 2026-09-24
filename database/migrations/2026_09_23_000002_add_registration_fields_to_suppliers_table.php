<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('company_title', 50)->nullable()->after('user_id');
            $table->string('nib', 50)->nullable()->after('npwp');
            $table->char('nib_fingerprint', 64)->nullable()->unique()->after('nib');
            $table->char('tax_identity_fingerprint', 64)->nullable()->unique()->after('nib_fingerprint');
        });

        // Backfill tax_identity_fingerprint for existing suppliers with an NPWP
        $suppliers = DB::table('suppliers')->whereNotNull('npwp')->where('npwp', '!=', '')->get(['id', 'npwp']);
        foreach ($suppliers as $supplier) {
            $normalized = preg_replace('/[^0-9A-Za-z]/', '', (string) $supplier->npwp);
            if ($normalized !== '') {
                $fingerprint = hash('sha256', $normalized);
                DB::table('suppliers')->where('id', $supplier->id)->update([
                    'tax_identity_fingerprint' => $fingerprint,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['tax_identity_fingerprint']);
            $table->dropUnique(['nib_fingerprint']);
            $table->dropColumn(['company_title', 'nib', 'nib_fingerprint', 'tax_identity_fingerprint']);
        });
    }
};
