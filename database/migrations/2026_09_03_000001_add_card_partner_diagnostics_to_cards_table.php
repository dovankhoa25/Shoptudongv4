<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->integer('partner_status')->nullable()->after('note');
            $table->text('partner_message')->nullable()->after('partner_status');
            $table->unsignedSmallInteger('partner_http_status')->nullable()->after('partner_message');
            $table->timestamp('partner_response_at')->nullable()->after('partner_http_status');
            $table->timestamp('callback_received_at')->nullable()->after('partner_response_at');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn([
                'partner_status',
                'partner_message',
                'partner_http_status',
                'partner_response_at',
                'callback_received_at',
            ]);
        });
    }
};
