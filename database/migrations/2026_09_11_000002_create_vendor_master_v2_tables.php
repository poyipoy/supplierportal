<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add fields to suppliers table for Vendor Master V2
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('vendor_category', 50)->nullable()->after('category');
            $table->boolean('is_pkp')->default(false)->after('vendor_category');
            $table->string('pic_name', 100)->nullable()->after('is_pkp');
            $table->string('pic_email', 100)->nullable()->after('pic_name');
            $table->string('pic_phone', 30)->nullable()->after('pic_email');
        });

        // 2. Versioned Supplier Bank Accounts
        Schema::create('supplier_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_holder_name', 150);
            $table->enum('status', ['PENDING', 'VERIFIED', 'REJECTED', 'INACTIVE'])->default('PENDING');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        // 3. Supplier Profile Change Requests (Approval Workflow: Purchasing or Finance)
        Schema::create('supplier_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->cascadeOnDelete();
            $table->string('change_type', 50)->default('profile');
            $table->json('current_data_snapshot')->nullable();
            $table->json('proposed_data');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('PENDING');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        // 4. Vendor Master Private Documents (NIB, NPWP, SPPKP, Surat Pernyataan Rekening)
        Schema::create('supplier_master_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->cascadeOnDelete();
            $table->enum('document_type', ['NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'OTHER']);
            $table->string('file_path')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_master_documents');
        Schema::dropIfExists('supplier_change_requests');
        Schema::dropIfExists('supplier_bank_accounts');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['vendor_category', 'is_pkp', 'pic_name', 'pic_email', 'pic_phone']);
        });
    }
};
