<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_goods_locations')) {
            Schema::create('supplier_goods_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('supplier_id');

                $table->string('name', 191);
                $table->string('contact', 150)->nullable();
                $table->text('address');
                $table->string('country', 120);
                $table->string('city', 120);
                $table->string('telephone', 60)->nullable();
                $table->string('mobile', 60)->nullable();
                $table->string('fax', 60)->nullable();
                $table->string('email', 191)->nullable();

                $table->boolean('is_default')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['supplier_id', 'deleted_at', 'is_default'], 'idx_goods_loc_supplier_active');
                $table->foreign('supplier_id', 'fk_goods_loc_supplier')
                    ->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('created_by', 'fk_goods_loc_creator')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by', 'fk_goods_loc_updater')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('shipment_preshipment_inspections', 'goods_location_id')) {
            Schema::table('shipment_preshipment_inspections', function (Blueprint $table) {
                $table->unsignedBigInteger('goods_location_id')
                    ->nullable()
                    ->after('shipment_id');
                $table->boolean('supplier_same_as_exporter')
                    ->default(false)
                    ->after('supplier_email');
                $table->unsignedSmallInteger('revision_count')
                    ->default(0)
                    ->after('supplier_same_as_exporter');
                $table->timestamp('last_revised_at')
                    ->nullable()
                    ->after('revision_count');
                $table->unsignedBigInteger('updated_by')
                    ->nullable()
                    ->after('last_revised_at');

                $table->index('goods_location_id', 'idx_psi_goods_location');
                $table->foreign('goods_location_id', 'fk_psi_goods_location')
                    ->references('id')->on('supplier_goods_locations')->nullOnDelete();
                $table->foreign('updated_by', 'fk_psi_updater')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        // Idempotent backfill: move legacy supplier_* fields on supplier_preshipment_profiles
        // into an initial default supplier_goods_locations row when none exists for that supplier.
        DB::table('supplier_preshipment_profiles')
            ->orderBy('id')
            ->chunkById(200, function ($profiles): void {
                $now = now();
                foreach ($profiles as $profile) {
                    $hasSupplierData = false;
                    foreach ([
                        'supplier_name',
                        'supplier_contact',
                        'supplier_address',
                        'supplier_country',
                        'supplier_city',
                        'supplier_telephone',
                        'supplier_mobile',
                        'supplier_fax',
                        'supplier_email',
                    ] as $col) {
                        if (trim((string) ($profile->{$col} ?? '')) !== '') {
                            $hasSupplierData = true;
                            break;
                        }
                    }

                    if (! $hasSupplierData) {
                        continue;
                    }

                    $alreadyExists = DB::table('supplier_goods_locations')
                        ->where('supplier_id', $profile->supplier_id)
                        ->exists();

                    if ($alreadyExists) {
                        continue;
                    }

                    $name = trim((string) ($profile->supplier_name ?? ''));
                    if ($name === '') {
                        $name = trim((string) ($profile->exporter_name ?? ''));
                    }
                    if ($name === '') {
                        $name = 'Main location';
                    }

                    $address = trim((string) ($profile->supplier_address ?? ''));
                    if ($address === '') {
                        $address = trim((string) ($profile->exporter_address ?? ''));
                    }
                    if ($address === '') {
                        $address = '-';
                    }

                    $country = trim((string) ($profile->supplier_country ?? ''));
                    if ($country === '') {
                        $country = trim((string) ($profile->exporter_country ?? ''));
                    }

                    $city = trim((string) ($profile->supplier_city ?? ''));
                    if ($city === '') {
                        $city = trim((string) ($profile->exporter_city ?? ''));
                    }

                    DB::table('supplier_goods_locations')->insert([
                        'supplier_id' => $profile->supplier_id,
                        'name' => mb_substr($name, 0, 191),
                        'contact' => $this->nullableTrim($profile->supplier_contact),
                        'address' => $address,
                        'country' => mb_substr($country, 0, 120),
                        'city' => mb_substr($city, 0, 120),
                        'telephone' => $this->nullableTrim($profile->supplier_telephone),
                        'mobile' => $this->nullableTrim($profile->supplier_mobile),
                        'fax' => $this->nullableTrim($profile->supplier_fax),
                        'email' => $this->nullableTrim($profile->supplier_email),
                        'is_default' => true,
                        'created_by' => $profile->updated_by ?? $profile->supplier_id,
                        'updated_by' => $profile->updated_by ?? $profile->supplier_id,
                        'created_at' => $profile->created_at ?? $now,
                        'updated_at' => $profile->updated_at ?? $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('supplier_goods_locations') && DB::table('supplier_goods_locations')->exists()) {
            throw new RuntimeException(
                'Cannot roll back 2026_11_19_000005_extend_preshipment_inspection: supplier_goods_locations already contains records.'
            );
        }

        if (Schema::hasTable('shipment_preshipment_inspections') && Schema::hasColumn('shipment_preshipment_inspections', 'goods_location_id')) {
            $hasExtendedData = DB::table('shipment_preshipment_inspections')
                ->where(function ($query): void {
                    $query->whereNotNull('goods_location_id')
                        ->orWhere('supplier_same_as_exporter', true)
                        ->orWhere('revision_count', '>', 0)
                        ->orWhereNotNull('last_revised_at')
                        ->orWhereNotNull('updated_by');
                })
                ->exists();

            if ($hasExtendedData) {
                throw new RuntimeException(
                    'Cannot roll back 2026_11_19_000005_extend_preshipment_inspection: shipment_preshipment_inspections contains extended PSI data.'
                );
            }
        }

        Schema::table('shipment_preshipment_inspections', function (Blueprint $table) {
            $table->dropForeign('fk_psi_goods_location');
            $table->dropForeign('fk_psi_updater');
            $table->dropIndex('idx_psi_goods_location');
            $table->dropColumn([
                'goods_location_id',
                'supplier_same_as_exporter',
                'revision_count',
                'last_revised_at',
                'updated_by',
            ]);
        });

        Schema::dropIfExists('supplier_goods_locations');
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
};
