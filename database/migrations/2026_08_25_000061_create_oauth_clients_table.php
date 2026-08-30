<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table): void {

            $table->char('id', 36);
            $table->string('owner_type', 191)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('name', 191);
            $table->string('secret', 191)->nullable();
            $table->string('provider', 191)->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('is_first_party')->default(false);
            $table->boolean('allows_direct_login')->default(false);
            $table->json('allowed_origins')->nullable();
            $table->boolean('revoked');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['owner_type', 'owner_id'], 'oauth_clients_owner_type_owner_id_index');
            $table->primary(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
