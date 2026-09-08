<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('nro_accounts', function (Blueprint $table) {
            $table->boolean('auto_publish')->default(false);
            $table->json('publish_config')->nullable();
            $table->string('publish_status', 30)->nullable();
            $table->text('publish_error')->nullable();
        });
    }
    public function down(): void {
        Schema::table('nro_accounts', fn (Blueprint $table) => $table->dropColumn(['auto_publish', 'publish_config', 'publish_status', 'publish_error']));
    }
};
