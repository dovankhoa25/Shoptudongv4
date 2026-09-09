<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('nro_accounts', fn (Blueprint $t) => $t->json('delivery_activity')->nullable()); }
    public function down(): void { Schema::table('nro_accounts', fn (Blueprint $t) => $t->dropColumn('delivery_activity')); }
};
