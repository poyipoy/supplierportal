<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of supplier invitations added to or removed from a
 * submitted purchase requisition. supplier_id and actor_id are plain copies
 * of users.id (no foreign key) so an audit row neither blocks nor disappears
 * with the pivot row it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_supplier_invitation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pr_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('event', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['pr_id', 'created_at', 'id'], 'pr_supplier_events_pr_idx');
        });
    }

    public function down(): void
    {
        $events = DB::table('pr_supplier_invitation_events')->count();

        if ($events > 0) {
            throw new RuntimeException("Cannot roll back supplier invitation history: {$events} audit row(s) exist.");
        }

        Schema::dropIfExists('pr_supplier_invitation_events');
    }
};
