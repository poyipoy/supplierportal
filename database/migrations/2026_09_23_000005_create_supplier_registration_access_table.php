<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_registration_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique('reg_access_user_unique')->constrained('users')->cascadeOnDelete();
            $table->string('registration_reference', 32)->unique('reg_access_ref_unique');
            $table->string('token_hash', 255);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['registration_reference', 'revoked_at'], 'reg_access_ref_rev_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_registration_access');
    }
};
