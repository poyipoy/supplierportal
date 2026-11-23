<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications') && ! $this->hasIndex('notifications', 'notifications_notifiable_read_created_idx')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_notifiable_read_created_idx');
            });
        }

        if (Schema::hasTable('jobs') && ! $this->hasIndex('jobs', 'jobs_queue_reserved_available_idx')) {
            Schema::table('jobs', function (Blueprint $table): void {
                $table->index(['queue', 'reserved_at', 'available_at'], 'jobs_queue_reserved_available_idx');
            });
        }

        if (
            Schema::hasTable('quotation_items')
            && $this->hasIndex('quotation_items', 'quotation_items_pr_item_quotation_index')
            && $this->hasIndex('quotation_items', 'quotation_items_pr_item_id_quotation_id_index')
        ) {
            Schema::table('quotation_items', function (Blueprint $table): void {
                $table->dropIndex('quotation_items_pr_item_quotation_index');
            });
        }

        if (
            Schema::hasTable('payment_items')
            && $this->hasIndex('payment_items', 'idx_payment_items_reservation')
            && $this->hasIndex('payment_items', 'payment_items_payable_type_payable_id_status_index')
        ) {
            Schema::table('payment_items', function (Blueprint $table): void {
                $table->dropIndex('idx_payment_items_reservation');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_items') && ! $this->hasIndex('payment_items', 'idx_payment_items_reservation')) {
            Schema::table('payment_items', function (Blueprint $table): void {
                $table->index(['payable_type', 'payable_id', 'status'], 'idx_payment_items_reservation');
            });
        }

        if (Schema::hasTable('quotation_items') && ! $this->hasIndex('quotation_items', 'quotation_items_pr_item_quotation_index')) {
            Schema::table('quotation_items', function (Blueprint $table): void {
                $table->index(['pr_item_id', 'quotation_id'], 'quotation_items_pr_item_quotation_index');
            });
        }

        if (Schema::hasTable('jobs') && $this->hasIndex('jobs', 'jobs_queue_reserved_available_idx')) {
            Schema::table('jobs', function (Blueprint $table): void {
                $table->dropIndex('jobs_queue_reserved_available_idx');
            });
        }

        if (Schema::hasTable('notifications') && $this->hasIndex('notifications', 'notifications_notifiable_read_created_idx')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->dropIndex('notifications_notifiable_read_created_idx');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
