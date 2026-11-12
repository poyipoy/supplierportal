<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_price_rule_sets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version_no')->unique();
            $table->string('status', 20)->default('archived');
            $table->string('import_mode', 20)->nullable();
            $table->string('source_file_name', 255)->nullable();
            $table->string('source_file_hash', 64)->nullable();
            $table->string('source_sheet', 50)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reference_price_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('active_rule_set_id')->nullable()->constrained('reference_price_rule_sets')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('reference_price_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('reference_price_rule_sets')->cascadeOnDelete();
            $table->string('category_key', 50);
            $table->string('category_name', 100);
            $table->unsignedInteger('dimension_min');
            $table->unsignedInteger('dimension_max');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['rule_set_id', 'category_key'], 'ref_rules_set_key_unique');
            $table->index(['rule_set_id', 'status', 'dimension_min', 'dimension_max'], 'ref_rules_set_status_dim_idx');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE reference_price_rules
                ADD CONSTRAINT ref_rules_dimension_range_check
                CHECK (dimension_min > 0 AND dimension_max >= dimension_min)
                SQL);
        }

        Schema::create('reference_price_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('source_file_name', 255);
            $table->string('private_storage_path', 255);
            $table->string('source_file_hash', 64);
            $table->string('source_sheet', 50);
            $table->string('import_mode', 20);
            $table->string('status', 30);
            $table->unsignedInteger('parsed_count')->default(0);
            $table->json('preview_diff')->nullable();
            $table->json('validation_errors')->nullable();
            $table->foreignId('confirmed_rule_set_id')->nullable()->constrained('reference_price_rule_sets')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_price_imports');
        Schema::dropIfExists('reference_price_rules');
        Schema::dropIfExists('reference_price_settings');
        Schema::dropIfExists('reference_price_rule_sets');
    }
};
