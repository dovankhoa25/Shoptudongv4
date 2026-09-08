<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nro_accounts', function (Blueprint $t) {
            $t->text('game_password')->nullable();
            $t->string('usage_type', 20)->nullable();
            $t->unsignedSmallInteger('server_index')->nullable(); // Zero based NroLoginCore index, NOT server DB id.
            $t->unsignedBigInteger('latest_snapshot_id')->nullable();
            $t->timestamp('last_synced_at')->nullable();
        });
        Schema::create('nro_account_snapshots', function (Blueprint $t) {
            $t->id(); $t->foreignId('account_id')->constrained('nro_accounts');
            $t->unsignedInteger('schema_version')->default(1);
            $t->string('catalog_version', 50); $t->timestamp('captured_at');
            $t->json('data_json'); $t->json('completeness_json'); $t->json('summary_json'); $t->timestamps();
        });
        Schema::table('nicks', function (Blueprint $t) {
            $t->foreignId('game_account_id')->nullable()->constrained('nro_accounts');
            $t->foreignId('snapshot_id')->nullable()->constrained('nro_account_snapshots');
        });
        Schema::create('nro_inventory_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('account_id')->constrained('nro_accounts');
            $t->string('fingerprint', 64); $t->unsignedInteger('template_id');
            $t->json('item_json'); $t->json('locations_json');
            $t->unsignedInteger('quantity')->default(0); $t->unsignedInteger('reserved')->default(0);
            $t->timestamps(); $t->unique(['account_id', 'fingerprint']);
        });
        Schema::create('item_listings', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->foreignId('account_id')->constrained('nro_accounts');
            $t->string('title', 180); $t->text('description')->nullable(); $t->unsignedBigInteger('price');
            $t->string('status', 20)->default('draft'); $t->timestamps();
        });
        Schema::create('item_listing_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('listing_id')->constrained('item_listings');
            $t->foreignId('inventory_item_id')->constrained('nro_inventory_items'); $t->unsignedInteger('quantity');
            $t->unique(['listing_id', 'inventory_item_id']);
        });
        Schema::create('item_orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('buyer_id')->constrained('users'); $t->foreignId('seller_id')->constrained('users');
            $t->foreignId('account_id')->constrained('nro_accounts'); $t->foreignId('listing_id')->constrained('item_listings');
            $t->string('request_key', 80); $t->string('recipient_name', 50); $t->unsignedSmallInteger('server_index');
            $t->unsignedBigInteger('price'); $t->string('title', 180); $t->string('status', 30)->default('queued');
            $t->string('delivery_message', 250)->nullable(); $t->timestamps(); $t->unique(['buyer_id', 'request_key']);
        });
        Schema::create('item_order_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained('item_orders');
            $t->foreignId('inventory_item_id')->constrained('nro_inventory_items'); $t->json('item_json');
            $t->unsignedInteger('quantity'); $t->unsignedInteger('delivered')->default(0);
        });
        Schema::create('item_inventory_reservations', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained('item_orders');
            $t->foreignId('inventory_item_id')->constrained('nro_inventory_items'); $t->unsignedInteger('quantity');
            $t->string('status', 20)->default('held'); $t->timestamps();
        });
        Schema::create('nro_worker_keys', function (Blueprint $t) {
            $t->id(); $t->string('name', 100); $t->string('token_hash', 64)->unique();
            $t->timestamp('revoked_at')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamps();
        });
        Schema::create('nro_worker_jobs', function (Blueprint $t) {
            $t->id(); $t->foreignId('account_id')->constrained('nro_accounts');
            $t->foreignId('order_id')->nullable()->constrained('item_orders');
            $t->string('type', 20); $t->string('status', 25)->default('queued');
            $t->foreignId('worker_key_id')->nullable()->constrained('nro_worker_keys');
            $t->uuid('lease_token')->nullable(); $t->timestamp('lease_until')->nullable();
            $t->json('result_json')->nullable(); $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('nro_worker_jobs'); Schema::dropIfExists('nro_worker_keys');
        Schema::dropIfExists('item_inventory_reservations'); Schema::dropIfExists('item_order_items');
        Schema::dropIfExists('item_orders'); Schema::dropIfExists('item_listing_items'); Schema::dropIfExists('item_listings');
        Schema::dropIfExists('nro_inventory_items');
        Schema::table('nicks', function (Blueprint $t) { $t->dropConstrainedForeignId('snapshot_id'); $t->dropConstrainedForeignId('game_account_id'); });
        Schema::dropIfExists('nro_account_snapshots');
        Schema::table('nro_accounts', fn (Blueprint $t) => $t->dropColumn(['game_password', 'usage_type', 'server_index', 'latest_snapshot_id', 'last_synced_at']));
    }
};
