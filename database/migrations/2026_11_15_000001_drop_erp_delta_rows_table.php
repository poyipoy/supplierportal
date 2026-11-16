<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('erp_delta_rows');
    }

    public function down(): void
    {
        // ERP Delta Sync was permanently decommissioned. Re-creating an empty
        // table here would not restore purged operational data; restore from
        // the pre-deploy database backup if a full rollback is required.
    }
};
