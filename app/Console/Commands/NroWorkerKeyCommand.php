<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class NroWorkerKeyCommand extends Command
{
    protected $signature = 'nro:worker-key {name=May-chu-NRO} {--revoke= : ID key cần thu hồi}';
    protected $description = 'Tạo API key riêng cho worker NRO; chỉ hiển thị token một lần';
    public function handle(): int
    {
        if ($id = $this->option('revoke')) {
            DB::table('nro_worker_keys')->where('id', $id)->update(['revoked_at' => now()]);
            $this->info('Đã thu hồi key.'); return self::SUCCESS;
        }
        $token = 'nrow_'.Str::random(64);
        $id = DB::table('nro_worker_keys')->insertGetId(['name' => $this->argument('name'), 'token_hash' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now()]);
        $this->info("Key ID: $id"); $this->line($token); return self::SUCCESS;
    }
}
