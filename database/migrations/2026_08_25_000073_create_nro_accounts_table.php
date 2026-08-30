<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nro_accounts', function (Blueprint $table): void {

            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_name', 141);
            $table->string('server', 50)->nullable();
            $table->string('character_name', 191)->nullable();
            $table->string('status', 50)->default('active');
            $table->string('locked_reason', 191)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_name', 'server']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nro_accounts');
    }
};
