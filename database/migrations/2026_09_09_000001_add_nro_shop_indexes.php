<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the admin tabs and the public shop.
 *
 * Single foreign-key columns are deliberately absent: InnoDB already indexes every column it
 * constrains, so account_id, listing_id and order_id are covered by the create migration's
 * foreignId()->constrained() calls. Only the (column, status) pairs the queries actually filter
 * on together, plus the bare status column the public shop scans, are missing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_listings', function (Blueprint $t) {
            // Admin tab: account_id IN (...) AND status = ?
            $t->index(['account_id', 'status'], 'item_listings_account_status_index');
            // Public shop: status = 'active' across every account.
            $t->index('status', 'item_listings_status_index');
        });
        // Admin orders tab, and the "kho có đơn chưa giao" guard when changing server.
        Schema::table('item_orders', fn (Blueprint $t) => $t->index(['account_id', 'status'], 'item_orders_account_status_index'));
        Schema::table('nro_worker_jobs', function (Blueprint $t) {
            // Every write path checks for queued/processing/review jobs on one account before acting.
            $t->index(['account_id', 'status'], 'nro_worker_jobs_account_status_index');
            // Worker claim and the expired-lease sweep scan by status, then type.
            $t->index(['status', 'type'], 'nro_worker_jobs_status_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('item_listings', function (Blueprint $t) {
            $t->dropIndex('item_listings_account_status_index'); $t->dropIndex('item_listings_status_index');
        });
        Schema::table('item_orders', fn (Blueprint $t) => $t->dropIndex('item_orders_account_status_index'));
        Schema::table('nro_worker_jobs', function (Blueprint $t) {
            $t->dropIndex('nro_worker_jobs_account_status_index'); $t->dropIndex('nro_worker_jobs_status_type_index');
        });
    }
};
