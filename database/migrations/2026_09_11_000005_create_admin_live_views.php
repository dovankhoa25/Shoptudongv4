<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('admin_live_views')) Schema::create('admin_live_views',function(Blueprint $t) {
            $t->uuid('id')->primary();$t->unsignedBigInteger('user_id')->index();$t->text('url');
            $t->string('mode',20);$t->json('resources');$t->timestamp('expires_at')->index();
        });
        if (!Schema::hasColumn('admin_live_views','credential_hash')) Schema::table('admin_live_views',fn(Blueprint $t)=>$t->string('credential_hash',64)->nullable());
        if (!Schema::hasColumn('admin_live_views','revision')) Schema::table('admin_live_views',fn(Blueprint $t)=>$t->unsignedBigInteger('revision')->default(0));
        if (!Schema::hasTable('user_balance_realtime')) Schema::create('user_balance_realtime',function(Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();$t->bigInteger('balance');$t->unsignedBigInteger('revision')->default(1);
        });
    }
    public function down(): void {Schema::dropIfExists('admin_live_views');Schema::dropIfExists('user_balance_realtime');}
};
