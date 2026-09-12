<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if(!Schema::hasColumn('nro_worker_jobs','recovery_json')) Schema::table('nro_worker_jobs',fn(Blueprint $t)=>$t->longText('recovery_json')->nullable()); if(!Schema::hasColumn('nro_accounts','login_sale_blocked')) Schema::table('nro_accounts',fn(Blueprint $t)=>$t->boolean('login_sale_blocked')->default(false)); }
    public function down(): void { if(Schema::hasColumn('nro_worker_jobs','recovery_json')) Schema::table('nro_worker_jobs',fn(Blueprint $t)=>$t->dropColumn('recovery_json')); if(Schema::hasColumn('nro_accounts','login_sale_blocked')) Schema::table('nro_accounts',fn(Blueprint $t)=>$t->dropColumn('login_sale_blocked')); }
};
