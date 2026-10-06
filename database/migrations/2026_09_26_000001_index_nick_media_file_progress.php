<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nick_media_files', function (Blueprint $table): void {
            $table->index(['publication_id', 'status', 'position'], 'nick_media_publication_status_position_index');
        });
    }

    public function down(): void
    {
        Schema::table('nick_media_files', function (Blueprint $table): void {
            $table->dropIndex('nick_media_publication_status_position_index');
        });
    }
};
