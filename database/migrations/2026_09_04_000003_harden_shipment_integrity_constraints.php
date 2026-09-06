<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasInvalidQuantity = DB::table('shipment_items')
            ->where('shipped_quantity', '<=', 0)
            ->exists();
        if ($hasInvalidQuantity) {
            throw new RuntimeException('Cannot add shipment quantity CHECK constraint while non-positive rows exist.');
        }

        $hasDuplicateDocumentType = DB::table('shipment_documents')
            ->select(['shipment_id', 'doc_type'])
            ->groupBy(['shipment_id', 'doc_type'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($hasDuplicateDocumentType) {
            throw new RuntimeException('Cannot add shipment document uniqueness while duplicate document types exist.');
        }

        Schema::table('shipment_documents', function (Blueprint $table): void {
            $table->unique(
                ['shipment_id', 'doc_type'],
                'shipment_documents_unique_type'
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE shipment_items
                ADD CONSTRAINT shipment_items_shipped_quantity_positive
                CHECK (shipped_quantity > 0)
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE shipment_items
                DROP CHECK shipment_items_shipped_quantity_positive
                SQL);
        }

        Schema::table('shipment_documents', function (Blueprint $table): void {
            $table->dropUnique('shipment_documents_unique_type');
        });
    }
};
