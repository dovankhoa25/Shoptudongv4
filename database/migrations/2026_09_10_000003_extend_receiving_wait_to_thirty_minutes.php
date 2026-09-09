<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        foreach (['nro_accounts','nro_delivery_sessions'] as $table) Schema::table($table, fn (Blueprint $t) => $t->unsignedSmallInteger('wait_minutes')->default(30)->change());
        DB::table('nro_accounts')->whereNotNull('usage_type')->update(['wait_minutes' => 30]);
        DB::table('nro_delivery_sessions')->whereIn('status', ['queued','preparing','ready','trading'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $data = ['wait_minutes' => 30];
                if ($row->expires_at) $data['expires_at'] = \Carbon\Carbon::parse($row->expires_at)->addSeconds(max(0, 30 - $row->wait_minutes) * 60);
                DB::table('nro_delivery_sessions')->where('id', $row->id)->update($data);
            }
        });
    }
    public function down(): void {
        // Do not shorten deadlines already promised to customers when rolling back defaults.
        foreach (['nro_accounts','nro_delivery_sessions'] as $table) Schema::table($table, fn (Blueprint $t) => $t->unsignedSmallInteger('wait_minutes')->default(10)->change());
    }
};
