<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','purchasing','supplier','qc','accounting','finance') NOT NULL DEFAULT 'supplier'");
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_term_days')->nullable();
        });
        Schema::create('supplier_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->cascadeOnDelete();
            $table->enum('scope', ['import', 'local'])->index();
            $table->timestamps();
            $table->unique(['supplier_id', 'scope']);
        });
        DB::transaction(function () {
            DB::table('users')->where('role', 'supplier')->orderBy('id')->chunkById(500, function ($users) {
                DB::table('supplier_scopes')->insertOrIgnore($users->map(fn ($user) => [
                    'supplier_id' => $user->id, 'scope' => 'import',
                    'created_at' => now(), 'updated_at' => now(),
                ])->all());
            });
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereIn('role', ['accounting', 'finance'])->exists()) {
            throw new RuntimeException('Reassign Accounting/Finance users before rolling back supplier scopes.');
        }
        Schema::dropIfExists('supplier_scopes');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('payment_term_days'));
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','purchasing','supplier','qc') NOT NULL DEFAULT 'supplier'");
    }
};
