<?php
namespace App\Console\Commands;

use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use App\Models\User;
use App\Services\NroCatalog;
use App\Services\NroItemFilters;
use App\Support\ApiCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NroDemoItems extends Command
{
    protected $signature = 'nro:demo-items {--owner=1 : User sở hữu dữ liệu demo}';
    protected $description = 'Thêm 500 món / 400 gói demo để thử bộ lọc trên database local, không tạo giao dịch game';
    public const BATCH = 'DEMO-FILTER-20260910';

    public function handle(): int
    {
        $connection = config('database.default');
        $host = config("database.connections.$connection.host");
        if (!app()->environment(['local', 'testing']) || ($connection !== 'sqlite' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            $this->error('Chỉ chạy trên database local hoặc test.'); return self::FAILURE;
        }
        $owner = User::findOrFail((int)$this->option('owner'));
        $existing = NroAccount::withTrashed()->where('account_name', 'like', self::BATCH.'-%')->pluck('id');
        if ($existing->isNotEmpty()) {
            $this->info('Lô demo đã tồn tại; không thêm trùng.');
            return self::SUCCESS;
        }
        $servers = DB::table('servers')->where('status', true)->orderBy('id')->limit(3)->get();
        if ($servers->isEmpty()) { $this->error('Chưa có server hiển thị.'); return self::FAILURE; }
        $filters = app(NroItemFilters::class); $catalog = app(NroCatalog::class);
        $templates = json_decode(file_get_contents(resource_path('nro/item-templates.json')), true);
        $pools = [];
        foreach ($templates as $template) $pools[$filters->group($template)][] = $template;
        $rotation = ['equipment','equipment','equipment','equipment','dragon_balls','upgrade_stones','crystals','support','support','other'];
        foreach (array_unique($rotation) as $group) if (empty($pools[$group])) { $this->error('Thiếu catalog nhóm '.$group); return self::FAILURE; }
        $result = DB::transaction(function () use ($owner,$servers,$filters,$catalog,$pools,$rotation) {
            $accounts = []; $bags = []; $cursors = []; $number = 0; $listingIds = [];
            foreach ($servers as $i=>$server) {
                $accounts[] = NroAccount::create(['user_id'=>$owner->id,'account_name'=>self::BATCH.'-SV'.$server->id,
                    'character_name'=>'Kho DEMO SV'.$server->id,'status'=>'demo','locked_reason'=>'Dữ liệu demo bộ lọc, không đăng nhập game',
                    'usage_type'=>'warehouse','server_id'=>$server->id,'server_index'=>$i,'server'=>'demo-'.$server->id,
                    'game_password'=>null,'server_game_id'=>null,'auto_publish'=>false]);
            }
            for ($n=0; $n<400; $n++) {
                $account = $accounts[$n % count($accounts)];
                $lines = $n < 350 ? 1 : 3;
                $listingIds[] = $listing = DB::table('item_listings')->insertGetId(['user_id'=>$owner->id,'account_id'=>$account->id,
                    'title'=>self::BATCH.' #'.($n+1),'description'=>self::BATCH.' | Chỉ demo giao diện; chỉ số giả lập, không giao dịch thật.',
                    'price'=>(1+($n*37)%500)*1000,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
                for ($j=0; $j<$lines; $j++, $number++) {
                    $group = $rotation[$number % count($rotation)];
                    $cursor = $cursors[$group] ?? 0; $cursors[$group] = $cursor+1;
                    $template = $pools[$group][$cursor % count($pools[$group])];
                    $options = [];
                    if ($group === 'equipment') {
                        $stars = (intdiv($number,10) + $number % 4) % 10;
                        $stat = [50,77,103][$number % 3];
                        $options[] = ['optionId'=>$stat,'param'=>5+($number % 26)];
                        if ($stars > 0) { $options[]=['optionId'=>107,'param'=>$stars]; $options[]=['optionId'=>102,'param'=>$number % ($stars+1)]; }
                    }
                    $quantity = in_array($group,['upgrade_stones','crystals','support'],true) ? [1,10,100,300,500][intdiv($number,10) % 5] : 1;
                    $slot = count($bags[$account->id] ?? []);
                    $item = $catalog->item(['templateId'=>$template['id'],'slot'=>$slot,'quantity'=>$quantity,'options'=>$options]);
                    $bags[$account->id][] = $item;
                    $inventory = DB::table('nro_inventory_items')->insertGetId(['account_id'=>$account->id,
                        'fingerprint'=>hash('sha256',self::BATCH.':'.$number),'template_id'=>$template['id'],'item_json'=>json_encode($item),
                        'locations_json'=>json_encode([['location'=>'bag','slot'=>$slot,'quantity'=>$quantity]]),
                        'quantity'=>$quantity,'reserved'=>0,...$filters::inventoryColumns($item),...$filters::extraInventoryColumns($item),'created_at'=>now(),'updated_at'=>now()]);
                    DB::table('item_listing_items')->insert(['listing_id'=>$listing,'inventory_item_id'=>$inventory,'quantity'=>$quantity]);
                }
            }
            foreach ($accounts as $account) {
                $character=['name'=>$account->character_name,'gender'=>0,'power'=>100000];
                $snapshot=NroAccountSnapshot::create(['account_id'=>$account->id,'schema_version'=>1,'catalog_version'=>'demo',
                    'captured_at'=>now(),'data_json'=>['capturedAt'=>now()->toIso8601String(),'character'=>$character,'equipped'=>[],
                        'bag'=>$bags[$account->id],'chest'=>[],'collectionChest'=>[]],
                    'summary_json'=>['character'=>$character,'highlights'=>[],'itemPreviews'=>array_slice($bags[$account->id],0,6)],
                    'completeness_json'=>['bag'=>true,'chest'=>true]]);
                $account->update(['latest_snapshot_id'=>$snapshot->id,'last_synced_at'=>now()]);
            }
            return ['batch'=>self::BATCH,'items'=>$number,'listings'=>count($listingIds),'firstListing'=>min($listingIds),'lastListing'=>max($listingIds),
                'accounts'=>array_map(fn($a)=>$a->id,$accounts),'owner'=>$owner->id];
        });
        ApiCache::clearGroups(['public:nro-shop:listings']);
        $this->info(json_encode($result,JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
