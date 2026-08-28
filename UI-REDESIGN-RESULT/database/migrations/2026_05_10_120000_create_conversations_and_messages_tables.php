<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('conversable_type');       // App\Models\PurchaseRequirement or App\Models\PurchaseOrder
            $table->unsignedBigInteger('conversable_id');
            $table->foreignId('purchasing_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supplier_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['conversable_type', 'conversable_id']);
            // Prevent duplicate conversations for same context + same pair
            $table->unique(['conversable_type', 'conversable_id', 'purchasing_user_id', 'supplier_user_id'], 'conv_unique_pair');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
