<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Employee Master
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('department', 100);
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_holder_name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'department']);
        });

        // 2. GA Claims Domain
        Schema::create('ga_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number', 100)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->enum('claim_type', ['Entertain Sales', 'UPD Sales', 'UPD GA', 'Reimburse/Claim']);
            $table->date('claim_date');
            $table->decimal('amount', 20, 2);
            $table->text('description')->nullable();
            $table->enum('status', [
                'SUBMITTED',
                'BASIC_VERIFIED',
                'UNDER_VERIFICATION',
                'NEED_REVISION',
                'READY_TO_PAY',
                'PAID',
                'CANCELLED',
            ])->default('SUBMITTED');
            $table->unsignedInteger('revision_number')->default(1);
            $table->text('revision_reason')->nullable();

            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');

            $table->foreignId('basic_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('basic_verified_at')->nullable();

            $table->foreignId('finance_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finance_verified_at')->nullable();

            $table->timestamp('ready_to_pay_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'claim_date']);
            $table->index(['employee_id', 'status']);
        });

        // 3. Tanda Terima GA (TT-GA)
        Schema::create('ga_claim_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_claim_id')->unique()->constrained('ga_claims')->cascadeOnDelete();
            $table->string('receipt_number', 100)->unique();
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        // 4. GA Claim Supporting Documents
        Schema::create('ga_claim_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_claim_id')->constrained('ga_claims')->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->string('document_type', 50)->default('supporting');
            $table->string('file_path')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['ga_claim_id', 'revision_number']);
        });

        // 5. GA Claim Status Histories
        Schema::create('ga_claim_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ga_claim_id')->constrained('ga_claims')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_claim_status_histories');
        Schema::dropIfExists('ga_claim_documents');
        Schema::dropIfExists('ga_claim_receipts');
        Schema::dropIfExists('ga_claims');
        Schema::dropIfExists('employees');
    }
};
