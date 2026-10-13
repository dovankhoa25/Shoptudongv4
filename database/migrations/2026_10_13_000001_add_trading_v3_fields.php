<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach (['bots', 'gem_bots'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name): void {
                $t->unsignedBigInteger('stock_version')->default(0);
                $t->string('v3_worker_id', 64)->nullable();
                $t->timestamp('v3_lease_until')->nullable();
                if ($name === 'gem_bots') {
                    $t->unsignedBigInteger('gift_ticket_qty')->nullable();
                    $t->unsignedBigInteger('consign_item_qty')->nullable();
                }
            });
        }
        foreach (['gem_transactions', 'gold_transactions'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name): void {
                if ($name === 'gem_transactions') {
                    $t->unsignedBigInteger('bot_id')->nullable()->index();
                    $t->string('delivery_method', 24)->default('consignment');
                    $t->unsignedBigInteger('delivered_gem_qty')->default(0);
                    $t->unsignedBigInteger('bot_gem_spent_qty')->default(0);
                    $t->unsignedBigInteger('tickets_used_qty')->default(0);
                }
                $t->unsignedTinyInteger('processing_version')->nullable();
                $t->string('processing_owner', 64)->nullable();
                $t->string('processing_token', 64)->nullable();
                $t->unsignedInteger('character_id')->nullable();
                $t->string('delivery_phase', 40)->nullable();
                $t->json('delivery_data')->nullable();
                $t->timestamp('processing_started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->index(['bot_id', 'status'], $name.'_v3_bot_status');
                $t->index(['server_id', 'status', 'processing_version'], $name.'_v3_pending');
            });
        }
    }
    public function down(): void {
        foreach (['gem_transactions', 'gold_transactions'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name): void {
                $t->dropIndex($name.'_v3_bot_status'); $t->dropIndex($name.'_v3_pending');
                $t->dropColumn(['processing_version','processing_owner','processing_token','character_id','delivery_phase','delivery_data','processing_started_at','completed_at']);
                if ($name === 'gem_transactions') {$t->dropIndex('gem_transactions_bot_id_index');$t->dropColumn(['bot_id','delivery_method','delivered_gem_qty','bot_gem_spent_qty','tickets_used_qty']);}
            });
        }
        foreach (['bots','gem_bots'] as $name) Schema::table($name, function (Blueprint $t) use ($name): void {
            $t->dropColumn(['stock_version','v3_worker_id','v3_lease_until']);
            if ($name === 'gem_bots') $t->dropColumn(['gift_ticket_qty','consign_item_qty']);
        });
    }
};

