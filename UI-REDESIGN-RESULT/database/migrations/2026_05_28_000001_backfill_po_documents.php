<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill po_documents sehingga setiap PO memiliki 4 dokumen impor standar.
     * PO historis yang dibuat sebelum invariant ini diterapkan bisa memiliki
     * kurang dari 4 dokumen. Migration ini mengisi yang kurang tanpa mengganggu
     * dokumen yang sudah ada.
     */
    public function up(): void
    {
        $docTypes = ['invoice', 'bl', 'packing_list', 'form_e'];

        // Ambil semua PO yang belum punya 4 dokumen lengkap
        $poIds = DB::table('purchase_orders')
            ->pluck('id');

        $inserted = 0;

        foreach ($poIds as $poId) {
            $existingTypes = DB::table('po_documents')
                ->where('po_id', $poId)
                ->pluck('doc_type')
                ->toArray();

            $missingTypes = array_diff($docTypes, $existingTypes);

            foreach ($missingTypes as $type) {
                DB::table('po_documents')->insert([
                    'po_id' => $poId,
                    'doc_type' => $type,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            }
        }

        if ($inserted > 0) {
            // Log for visibility
            logger()->info("Backfilled {$inserted} po_documents records for " . $poIds->count() . " POs.");
        }
    }

    public function down(): void
    {
        // Tidak ada aksi rollback — dokumen yang di-backfill aman untuk tetap ada.
        // Menghapus secara membabi buta bisa menghapus dokumen yang sudah diupdate statusnya.
    }
};
