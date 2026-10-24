<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('bots',function(Blueprint $table){$table->unsignedInteger('nrj_gold_bar_qty')->default(0);});
        Schema::table('gold_transactions',function(Blueprint $table){$table->unsignedSmallInteger('gold_bar_template_id')->default(457);});
    }
    public function down(): void {
        Schema::table('gold_transactions',fn(Blueprint $table)=>$table->dropColumn('gold_bar_template_id'));
        Schema::table('bots',fn(Blueprint $table)=>$table->dropColumn('nrj_gold_bar_qty'));
    }
};
