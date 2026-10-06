<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nick_publications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->string('account_name');
            $table->longText('payload'); // Encrypted, including credentials.
            $table->string('request_hash', 64);
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->foreignId('nick_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('nick_media_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('publication_id')->nullable()->constrained('nick_publications');
            $table->unsignedInteger('position')->default(0);
            $table->text('source_url')->nullable();
            $table->string('path')->nullable();
            $table->string('name');
            $table->string('status', 20)->default('uploaded');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['publication_id', 'position']);
            $table->index(['status', 'queued_at']);
            $table->index(['user_id', 'publication_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nick_media_files');
        Schema::dropIfExists('nick_publications');
    }
};
