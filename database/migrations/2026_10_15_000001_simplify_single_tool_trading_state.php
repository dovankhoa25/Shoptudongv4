<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        foreach (['gem_transactions','gold_transactions'] as $name) {
            if (Schema::hasIndex($name,$name.'_v3_pending')) Schema::table($name,fn(Blueprint $t)=>$t->dropIndex($name.'_v3_pending'));
            $columns=array_values(array_filter(['processing_version','processing_owner','processing_token'],fn($column)=>Schema::hasColumn($name,$column)));
            if($columns)Schema::table($name,fn(Blueprint $t)=>$t->dropColumn($columns));
            if(!Schema::hasIndex($name,$name.'_pending_status'))Schema::table($name,fn(Blueprint $t)=>$t->index(['server_id','status'],$name.'_pending_status'));
        }
        foreach (['bots','gem_bots'] as $name) {
            $columns=array_values(array_filter(['v3_worker_id','v3_lease_until'],fn($column)=>Schema::hasColumn($name,$column)));
            if($columns)Schema::table($name,fn(Blueprint $t)=>$t->dropColumn($columns));
        }
    }
    public function down(): void {
        // Rollback restores only schema. Retired permission metadata is intentionally
        // not restored; all financial history, progress and delivery_data remain intact.
        foreach (['bots','gem_bots'] as $name) {
            $worker=!Schema::hasColumn($name,'v3_worker_id');$lease=!Schema::hasColumn($name,'v3_lease_until');
            if($worker||$lease)Schema::table($name,function(Blueprint $t) use($worker,$lease): void {
                if($worker)$t->string('v3_worker_id',64)->nullable();if($lease)$t->timestamp('v3_lease_until')->nullable();
            });
        }
        foreach (['gem_transactions','gold_transactions'] as $name) {
            if(Schema::hasIndex($name,$name.'_pending_status'))Schema::table($name,fn(Blueprint $t)=>$t->dropIndex($name.'_pending_status'));
            $version=!Schema::hasColumn($name,'processing_version');$owner=!Schema::hasColumn($name,'processing_owner');$token=!Schema::hasColumn($name,'processing_token');
            if($version||$owner||$token)Schema::table($name,function(Blueprint $t) use($version,$owner,$token): void {
                if($version)$t->unsignedTinyInteger('processing_version')->nullable();if($owner)$t->string('processing_owner',64)->nullable();if($token)$t->string('processing_token',64)->nullable();
            });
            if(!Schema::hasIndex($name,$name.'_v3_pending'))Schema::table($name,fn(Blueprint $t)=>$t->index(['server_id','status','processing_version'],$name.'_v3_pending'));
        }
    }
};
