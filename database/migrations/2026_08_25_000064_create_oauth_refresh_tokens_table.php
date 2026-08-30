<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table): void {

            $table->char('id', 80);
            $table->char('access_token_id', 80);
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
            $table->index(['access_token_id'], 'oauth_refresh_tokens_access_token_id_index');
            $table->primary(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
    }
};
