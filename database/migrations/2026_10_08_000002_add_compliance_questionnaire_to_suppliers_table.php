<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Kuesioner Kualitas & Kepatuhan dari registrasi: {version, answers{key: yes|no}, answered_at}
            $table->json('compliance_questionnaire')->nullable()->after('pic_phone');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('compliance_questionnaire');
        });
    }
};
