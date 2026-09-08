<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('nro_worker_keys', fn (Blueprint $t) => $t->boolean('accepts_delivery')->default(false)); }
    public function down(): void { Schema::table('nro_worker_keys', fn (Blueprint $t) => $t->dropColumn('accepts_delivery')); }
};
