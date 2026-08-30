<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carot_recharge_statistics', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('type', 10);
            $table->date('stat_date');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedTinyInteger('server_id')->nullable();
            $table->unsignedBigInteger('total_transactions')->default(0);
            $table->unsignedBigInteger('success_transactions')->default(0);
            $table->unsignedBigInteger('failed_transactions')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('total_carot')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['user_id'], 'carot_recharge_statistics_user_id_foreign');
            $table->index(['server_id'], 'crs_server_id_index');
            $table->unique(['type', 'stat_date', 'user_id', 'server_id'], 'crs_type_date_user_server_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carot_recharge_statistics');
    }
};
