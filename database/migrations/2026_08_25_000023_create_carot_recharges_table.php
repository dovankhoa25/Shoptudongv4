<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carot_recharges', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('account_name', 191);
            $table->unsignedTinyInteger('server_id');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('carot')->default(0);
            $table->string('transaction_code', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->longText('api_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_at'], 'carot_recharges_created_at_index');
            $table->index(['server_id'], 'carot_recharges_server_id_index');
            $table->unique(['transaction_code'], 'carot_recharges_transaction_code_unique');
            $table->index(['user_id', 'status'], 'carot_recharges_user_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carot_recharges');
    }
};
