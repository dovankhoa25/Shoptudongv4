<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nicks', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('account_name', 191);
            $table->string('account_password', 191);
            $table->decimal('price', 10, 0);
            $table->text('description')->nullable();
            $table->string('image', 191)->nullable();
            $table->enum('listing_type', ['normal', 'vip'])->default('normal');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 100)->default('not_sold');
            $table->longText('attribute_cache_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nicks');
    }
};
