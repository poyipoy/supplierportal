<?php

use App\Models\ShipmentDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision_count')
                ->default(0)
                ->after('notes');
            $table->timestamp('last_revised_at')
                ->nullable()
                ->after('revision_count');
            $table->string('revision_note', 500)
                ->nullable()
                ->after('last_revised_at');
        });

        // Backfill revision_count from existing attachments on ShipmentDocument
        $attachmentCounts = DB::table('attachments')
            ->where('attachable_type', ShipmentDocument::class)
            ->selectRaw('attachable_id, COUNT(*) as total, MAX(created_at) as latest_at')
            ->groupBy('attachable_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($attachmentCounts as $row) {
            DB::table('shipment_documents')
                ->where('id', $row->attachable_id)
                ->update([
                    'revision_count' => max(0, ((int) $row->total) - 1),
                    'last_revised_at' => $row->latest_at,
                ]);
        }

        // E3 Backfill: convert legacy 'awaiting_confirmation' progress updates to 'order_confirmed'
        if (Schema::hasTable('po_item_progress_updates')) {
            DB::table('po_item_progress_updates')
                ->where('status', 'awaiting_confirmation')
                ->update(['status' => 'order_confirmed']);
        }
    }

    public function down(): void
    {
        Schema::table('shipment_documents', function (Blueprint $table) {
            $table->dropColumn(['revision_count', 'last_revised_at', 'revision_note']);
        });
    }
};
