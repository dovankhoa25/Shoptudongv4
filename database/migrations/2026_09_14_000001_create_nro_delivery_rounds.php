<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('item_orders','failure_role')) Schema::table('item_orders',fn(Blueprint $table)=>$table->string('failure_role',10)->nullable());
        if (!Schema::hasTable('nro_delivery_rounds')) Schema::create('nro_delivery_rounds',function(Blueprint $table) {
            $table->id(); $table->uuid('round_key')->unique();
            $table->unsignedBigInteger('job_id')->index(); $table->unsignedBigInteger('order_id')->index();
            $table->string('status',20)->default('started'); $table->longText('before_json');
            $table->longText('result_json')->nullable(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('nro_delivery_rounds'); if (Schema::hasColumn('item_orders','failure_role')) Schema::table('item_orders',fn(Blueprint $table)=>$table->dropColumn('failure_role')); }
};
