<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_category_stats', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('category_id');
            $table->date('stat_date');
            $table->unsignedInteger('nick_total_count')->default(0);
            $table->decimal('nick_total_revenue', 15, 0)->unsigned()->default('0');
            $table->decimal('nick_total_commission', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('nick_sold_count')->default(0);
            $table->decimal('nick_sold_revenue', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('nick_deleted_count')->default(0);
            $table->decimal('nick_deleted_amount', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('nick_returned_count')->default(0);
            $table->decimal('nick_returned_amount', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('nick_available_count')->default(0);
            $table->decimal('nick_available_value', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('service_total_count')->default(0);
            $table->decimal('service_total_revenue', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('service_completed_count')->default(0);
            $table->decimal('service_completed_revenue', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('service_rejected_count')->default(0);
            $table->decimal('service_rejected_revenue', 15, 0)->unsigned()->default('0');
            $table->unsignedInteger('service_pending_count')->default(0);
            $table->unsignedInteger('service_approved_count')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_category_stats');
    }
};
