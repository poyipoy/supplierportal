<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_invoice_verifications', function (Blueprint $table): void {
            $table->foreignId('section_a_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('section_b_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('actor_tracking_required')->default(false);
        });
        Schema::table('ga_claims', function (Blueprint $table): void {
            $table->json('approved_bank_snapshot')->nullable();
            $table->string('approved_bank_hash', 64)->nullable();
        });
        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->boolean('actor_tracking_required')->default(false);
            $table->timestamp('transfer_exported_at')->nullable();
            $table->foreignId('transfer_exported_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_exported_by');
            $table->dropColumn(['actor_tracking_required', 'transfer_exported_at']);
        });
        Schema::table('ga_claims', fn (Blueprint $table) => $table->dropColumn(['approved_bank_snapshot', 'approved_bank_hash']));
        Schema::table('local_invoice_verifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_a_verified_by');
            $table->dropConstrainedForeignId('section_b_verified_by');
            $table->dropColumn('actor_tracking_required');
        });
    }
};
