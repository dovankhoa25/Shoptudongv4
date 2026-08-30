<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('random_nicks', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('random_box_id');
            $table->unsignedBigInteger('user_id');
            $table->string('account', 191);
            $table->string('password', 191);
            $table->string('image', 191)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['available', 'taken', 'deleted'])->default('available');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('random_nicks');
    }
};
