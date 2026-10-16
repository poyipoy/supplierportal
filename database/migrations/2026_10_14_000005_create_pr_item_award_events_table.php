<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of award selections, acceptances and freezes
 * (decision 02). Only pr_item_award_id is a foreign key (nullOnDelete): the
 * other identifiers are plain copies, so an audit row neither blocks nor
 * disappears with the existing PR -> award cascades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_item_award_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_item_award_id')->nullable()->constrained('pr_item_awards')->nullOnDelete();
            $table->unsignedBigInteger('pr_id');
            $table->unsignedBigInteger('pr_item_id');
            $table->unsignedBigInteger('quotation_item_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('event', 40);
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['pr_item_award_id', 'created_at', 'id']);
            $table->index(['pr_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $events = DB::table('pr_item_award_events')->count();

        if ($events > 0) {
            throw new RuntimeException("Cannot roll back award event history: {$events} audit row(s) exist.");
        }

        Schema::dropIfExists('pr_item_award_events');
    }
};
