<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buat tabel document_sequences untuk nomor PR/PO atomic.
     * Menghindari duplikat saat dua user submit bersamaan.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['PR', 'PO']);
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['type', 'year', 'month']);
        });

        // Backfill dari data yang ada: hitung berapa PR dan PO yang sudah ada per bulan
        $this->backfillSequences('PR', 'purchase_requirements');
        $this->backfillSequences('PO', 'purchase_orders');
    }

    private function backfillSequences(string $type, string $table): void
    {
        $rows = \Illuminate\Support\Facades\DB::table($table)
            ->selectRaw('YEAR(created_at) as y, MONTH(created_at) as m, COUNT(*) as total')
            ->whereNotNull('created_at')
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->get();

        foreach ($rows as $row) {
            \Illuminate\Support\Facades\DB::table('document_sequences')->insert([
                'type' => $type,
                'year' => $row->y,
                'month' => $row->m,
                'last_number' => $row->total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
