<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Note: Non-active rule set rows deleted during up() cannot be restored
     * on rollback.
     */
    public function up(): void
    {
        // 1. Tentukan rule_set_id aktif yang akan dipertahankan datanya
        $retainedRuleSetId = null;

        if (Schema::hasTable('reference_price_settings')) {
            $retainedRuleSetId = DB::table('reference_price_settings')
                ->whereNotNull('active_rule_set_id')
                ->orderByDesc('id')
                ->value('active_rule_set_id');
        }

        if ($retainedRuleSetId === null && Schema::hasTable('reference_price_rule_sets')) {
            $retainedRuleSetId = DB::table('reference_price_rule_sets')
                ->where('status', 'active')
                ->orderByDesc('activated_at')
                ->orderByDesc('version_no')
                ->orderByDesc('id')
                ->value('id');
        }

        if ($retainedRuleSetId === null && Schema::hasTable('reference_price_rule_sets')) {
            $retainedRuleSetId = DB::table('reference_price_rule_sets')
                ->orderByDesc('version_no')
                ->orderByDesc('id')
                ->value('id');
        }

        // 2. Pre-check duplikat category_key pada baris yang dipertahankan
        if (Schema::hasTable('reference_price_rules')) {
            $query = DB::table('reference_price_rules');
            if ($retainedRuleSetId !== null) {
                $query->where('rule_set_id', $retainedRuleSetId);
            }
            $duplicates = (clone $query)
                ->select('category_key', DB::raw('count(*) as count'))
                ->groupBy('category_key')
                ->having('count', '>', 1)
                ->pluck('category_key');

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException('Stopping condition met: duplicate category_key found in retained rule set: '.$duplicates->implode(', '));
            }

            // Hapus baris yang bukan milik rule set aktif
            if ($retainedRuleSetId !== null) {
                DB::table('reference_price_rules')
                    ->where('rule_set_id', '!=', $retainedRuleSetId)
                    ->delete();
            } else {
                DB::table('reference_price_rules')->delete();
            }

            Schema::table('reference_price_rules', function (Blueprint $table): void {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['rule_set_id']);
                }
                $table->dropUnique('ref_rules_set_key_unique');
                $table->dropIndex('ref_rules_set_status_dim_idx');
                $table->dropColumn('rule_set_id');

                $table->unique(['category_key'], 'ref_rules_category_key_unique');
                $table->index(['status', 'dimension_min', 'dimension_max'], 'ref_rules_status_dim_idx');
            });
        }

        // 3. Lepas confirmed_rule_set_id dari reference_price_imports
        if (Schema::hasTable('reference_price_imports') && Schema::hasColumn('reference_price_imports', 'confirmed_rule_set_id')) {
            Schema::table('reference_price_imports', function (Blueprint $table): void {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['confirmed_rule_set_id']);
                }
                $table->dropColumn('confirmed_rule_set_id');
            });
        }

        // 4. Hapus tabel pengaturan versi dan header versi (urutan mengikuti dependensi FK)
        Schema::dropIfExists('reference_price_settings');
        Schema::dropIfExists('reference_price_rule_sets');
    }

    /**
     * Reverse the migrations.
     *
     * Limitation: Rows from non-active rule sets that were pruned during up()
     * cannot be restored. Only currently existing rules are assigned to a restored default version.
     */
    public function down(): void
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

        if (Schema::hasTable('reference_price_rules')) {
            Schema::table('reference_price_rules', function (Blueprint $table): void {
                $table->dropUnique('ref_rules_category_key_unique');
                $table->dropIndex('ref_rules_status_dim_idx');
                $table->foreignId('rule_set_id')->nullable()->after('id');
            });

            $fallbackUserId = DB::table('reference_price_rules')->whereNotNull('created_by')->value('created_by')
                ?? DB::table('users')->orderBy('id')->value('id');

            if ($fallbackUserId !== null) {
                $defaultRuleSetId = DB::table('reference_price_rule_sets')->insertGetId([
                    'version_no' => 1,
                    'status' => 'active',
                    'created_by' => $fallbackUserId,
                    'activated_by' => $fallbackUserId,
                    'activated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('reference_price_settings')->insert([
                    'active_rule_set_id' => $defaultRuleSetId,
                    'updated_by' => $fallbackUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('reference_price_rules')->update(['rule_set_id' => $defaultRuleSetId]);
            }

            Schema::table('reference_price_rules', function (Blueprint $table): void {
                $table->unsignedBigInteger('rule_set_id')->nullable(false)->change();
                $table->foreign('rule_set_id')->references('id')->on('reference_price_rule_sets')->cascadeOnDelete();
                $table->unique(['rule_set_id', 'category_key'], 'ref_rules_set_key_unique');
                $table->index(['rule_set_id', 'status', 'dimension_min', 'dimension_max'], 'ref_rules_set_status_dim_idx');
            });
        }

        if (Schema::hasTable('reference_price_imports')) {
            Schema::table('reference_price_imports', function (Blueprint $table): void {
                $table->foreignId('confirmed_rule_set_id')
                    ->nullable()
                    ->after('validation_errors')
                    ->constrained('reference_price_rule_sets')
                    ->nullOnDelete();
            });
        }
    }
};
