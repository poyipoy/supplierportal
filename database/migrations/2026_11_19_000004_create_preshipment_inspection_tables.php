<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_preshipment_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->unique();

            // Exporter (Company issued invoice, PL)
            $table->string('exporter_name', 191)->nullable();
            $table->string('exporter_contact', 150)->nullable();
            $table->text('exporter_address')->nullable();
            $table->string('exporter_city', 120)->nullable();
            $table->string('exporter_country', 120)->nullable();
            $table->string('exporter_zip_code', 40)->nullable();
            $table->string('exporter_telephone', 60)->nullable();
            $table->string('exporter_mobile', 60)->nullable();
            $table->string('exporter_fax', 60)->nullable();
            $table->string('exporter_email', 191)->nullable();
            $table->string('exporter_port_of_loading', 150)->nullable();

            // Supplier (Location of Goods)
            $table->string('supplier_name', 191)->nullable();
            $table->string('supplier_contact', 150)->nullable();
            $table->text('supplier_address')->nullable();
            $table->string('supplier_country', 120)->nullable();
            $table->string('supplier_city', 120)->nullable();
            $table->string('supplier_telephone', 60)->nullable();
            $table->string('supplier_mobile', 60)->nullable();
            $table->string('supplier_fax', 60)->nullable();
            $table->string('supplier_email', 191)->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('supplier_id', 'fk_preshipment_profile_supplier')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('updated_by', 'fk_preshipment_profile_updater')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('shipment_preshipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id')->unique();

            // Exporter (Company issued invoice, PL)
            $table->string('exporter_name', 191)->nullable();
            $table->string('exporter_contact', 150)->nullable();
            $table->text('exporter_address')->nullable();
            $table->string('exporter_city', 120)->nullable();
            $table->string('exporter_country', 120)->nullable();
            $table->string('exporter_zip_code', 40)->nullable();
            $table->string('exporter_telephone', 60)->nullable();
            $table->string('exporter_mobile', 60)->nullable();
            $table->string('exporter_fax', 60)->nullable();
            $table->string('exporter_email', 191)->nullable();
            $table->string('exporter_port_of_loading', 150)->nullable();

            // Supplier (Location of Goods)
            $table->string('supplier_name', 191)->nullable();
            $table->string('supplier_contact', 150)->nullable();
            $table->text('supplier_address')->nullable();
            $table->string('supplier_country', 120)->nullable();
            $table->string('supplier_city', 120)->nullable();
            $table->string('supplier_telephone', 60)->nullable();
            $table->string('supplier_mobile', 60)->nullable();
            $table->string('supplier_fax', 60)->nullable();
            $table->string('supplier_email', 191)->nullable();

            $table->timestamps();

            $table->foreign('shipment_id', 'fk_preshipment_inspection_shipment')
                ->references('id')->on('shipments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_preshipment_inspections');
        Schema::dropIfExists('supplier_preshipment_profiles');
    }
};
