<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('nro_accounts', function (Blueprint $t) {
            $t->foreignId('server_id')->nullable()->constrained('servers');
            // Legacy server_game_login uses a signed integer without a declared primary key.
            $t->integer('server_game_id')->nullable()->index();
            $t->unsignedSmallInteger('delivery_map')->default(24);
            $t->unsignedSmallInteger('delivery_zone')->default(0);
            $t->unsignedSmallInteger('wait_minutes')->default(10);
        });
        Schema::create('nro_delivery_sessions', function (Blueprint $t) {
            $t->id(); $t->foreignId('order_id')->constrained('item_orders');
            $t->uuid('request_key'); $t->unique(['order_id', 'request_key']);
            $t->string('mode', 16); $t->string('status', 24)->default('queued');
            $t->string('recipient_name', 50)->nullable();
            $t->text('receiver_credentials')->nullable();
            $t->string('receiver_lock', 64)->nullable()->unique();
            $t->unsignedSmallInteger('wait_minutes')->default(10);
            $t->timestamp('ready_at')->nullable(); $t->timestamp('expires_at')->nullable();
            $t->json('position_json')->nullable(); $t->timestamps();
        });
        Schema::table('item_orders', fn (Blueprint $t) => $t->foreignId('server_id')->nullable()->constrained('servers'));
        Schema::table('nro_worker_jobs', fn (Blueprint $t) => $t->foreignId('delivery_session_id')->nullable()->constrained('nro_delivery_sessions'));
    }
    public function down(): void {
        Schema::table('nro_worker_jobs', fn (Blueprint $t) => $t->dropConstrainedForeignId('delivery_session_id'));
        Schema::dropIfExists('nro_delivery_sessions');
        Schema::table('item_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('server_id'));
        Schema::table('nro_accounts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('server_id'); $t->dropColumn('server_game_id');
            $t->dropColumn(['delivery_map', 'delivery_zone', 'wait_minutes']);
        });
    }
};
