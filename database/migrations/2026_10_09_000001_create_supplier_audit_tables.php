<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Audit: template checklist berversi, penugasan per supplier local,
 * jawaban ber-snapshot, dan riwayat status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_audit_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->unsignedSmallInteger('version');
            $table->string('title');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::create('supplier_audit_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_audit_template_id')->constrained('supplier_audit_templates')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('supplier_audit_sections')->restrictOnDelete();
            $table->string('code', 10);
            $table->string('title');
            $table->unsignedTinyInteger('level');
            $table->unsignedTinyInteger('sheet_number');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['supplier_audit_template_id', 'code'], 'supplier_audit_sections_template_code_unique');
            $table->index(['supplier_audit_template_id', 'sort_order'], 'supplier_audit_sections_template_sort_index');
        });

        Schema::create('supplier_audit_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_audit_section_id')->constrained('supplier_audit_sections')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('sort_order');
            $table->text('text');
            $table->timestamps();

            $table->unique(['supplier_audit_section_id', 'number'], 'supplier_audit_criteria_section_number_unique');
        });

        Schema::create('supplier_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_audit_template_id')->constrained('supplier_audit_templates')->restrictOnDelete();
            $table->string('period_label', 100);
            $table->date('due_date')->nullable();
            $table->enum('status', ['ASSIGNED', 'DRAFT', 'SUBMITTED', 'REVISION_REQUESTED', 'RESULT_PUBLISHED', 'CANCELLED'])->default('ASSIGNED');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('submitted_at')->nullable();
            $table->text('revision_note')->nullable();
            $table->timestamp('revision_requested_at')->nullable();
            $table->timestamp('result_published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index('period_label');
        });

        Schema::create('supplier_audit_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_audit_id')->constrained('supplier_audits')->cascadeOnDelete();
            $table->foreignId('supplier_audit_criterion_id')->constrained('supplier_audit_criteria')->restrictOnDelete();
            $table->string('parent_section_code_snapshot', 10)->nullable();
            $table->string('parent_section_title_snapshot')->nullable();
            $table->string('section_code_snapshot', 10);
            $table->string('section_title_snapshot');
            $table->unsignedTinyInteger('sheet_number_snapshot');
            $table->unsignedSmallInteger('criterion_number_snapshot');
            $table->unsignedSmallInteger('sort_order_snapshot');
            $table->text('criterion_text_snapshot');
            $table->enum('answer', ['YES', 'NO'])->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['supplier_audit_id', 'supplier_audit_criterion_id'], 'supplier_audit_answers_audit_criterion_unique');
            $table->index(['supplier_audit_id', 'sort_order_snapshot'], 'supplier_audit_answers_audit_sort_index');
        });

        // D3: Tidak → score kosong; Ya → score 1–5 atau kosong saat draft. MySQL ≥ 8.0.16 / MariaDB menegakkannya.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE supplier_audit_answers ADD CONSTRAINT supplier_audit_answers_score_check CHECK (
                (answer IS NULL AND score IS NULL)
                OR (answer = 'NO' AND score IS NULL)
                OR (answer = 'YES' AND (score IS NULL OR score BETWEEN 1 AND 5))
            )");
        }

        Schema::create('supplier_audit_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_audit_id')->constrained('supplier_audits')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event', 50);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['supplier_audit_id', 'created_at'], 'supplier_audit_histories_audit_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_audit_status_histories');
        Schema::dropIfExists('supplier_audit_answers');
        Schema::dropIfExists('supplier_audits');
        Schema::dropIfExists('supplier_audit_criteria');
        Schema::dropIfExists('supplier_audit_sections');
        Schema::dropIfExists('supplier_audit_templates');
    }
};
