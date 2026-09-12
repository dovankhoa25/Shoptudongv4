<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\NroShopService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
class NroShopController extends Controller
{
    public function index(Request $r, NroShopService $s, \App\Services\NroItemFilters $itemFilters)
    {
        $filters = $r->validate(['page' => 'sometimes|integer|min:1', 'server' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:100', 'bundle' => 'nullable|in:single,combo',
            'minPrice' => 'nullable|numeric|min:0|max:1000000000000',
            'maxPrice' => ['nullable','numeric','min:0','max:1000000000000', ...($r->filled('minPrice') ? ['gte:minPrice'] : [])],
            'sort' => 'nullable|in:newest,price_asc,price_desc',
            'group'=>'nullable|in:'.implode(',',array_keys(\App\Services\NroItemFilters::GROUPS)),
            'equipmentType'=>'nullable|in:0,1,2,3,4',
            'gender'=>'nullable|integer|in:0,1,2', 'minStars'=>'nullable|integer|min:1|max:9', 'stat'=>'nullable|in:'.implode(',',array_keys(\App\Services\NroItemFilters::STATS)), 'itemId'=>'nullable|integer|min:0|max:100000']);
        foreach (['equipmentType','gender','minStars','stat'] as $key) if (isset($filters[$key])) {
            if (($filters['group'] ?? '') !== 'equipment') throw \Illuminate\Validation\ValidationException::withMessages([$key=>'Chọn nhóm Trang bị để dùng bộ lọc này.']);
        }
        if (isset($filters['itemId']) && empty($filters['group'])) throw \Illuminate\Validation\ValidationException::withMessages(['itemId'=>'Chọn nhóm vật phẩm trước.']);
        $cachePayload = $filters;
        $cachePayload['q'] = trim((string) ($cachePayload['q'] ?? ''));
        if (($cachePayload['q'] ?? '') === '') unset($cachePayload['q']);
        ksort($cachePayload);
        $cacheKey = ApiCache::key('nro-shop:listings', 'filters-v5-visibility', (string) \Illuminate\Support\Facades\Cache::get('nro-shop:visibility-version', '0'), json_encode($cachePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $payload = ApiCache::remember('public:nro-shop:listings', $cacheKey, 60, function () use ($filters, $s, $itemFilters) {
            $q = DB::table('item_listings')->where('status', 'active')->whereIn('account_id', DB::table('nro_accounts')->select('id')->whereNull('deleted_at')->where('shop_hidden', false)->where('login_sale_blocked', false))->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('item_orders')->whereColumn('item_orders.listing_id', 'item_listings.id'));
            if (! empty($filters['server'])) $q->whereIn('account_id', DB::table('nro_accounts')->select('id')->where('server_id', (int) $filters['server']));
            $search = trim((string) ($filters['q'] ?? ''));
            if ($search !== '' && empty($filters['group'])) {
                // Search public item names/IDs only; internal listing notes stay private.
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $q->whereExists(function ($items) use ($search, $pattern) {
                    $items->selectRaw('1')->from('item_listing_items as li')->join('nro_inventory_items as i', 'i.id', '=', 'li.inventory_item_id')
                        ->whereColumn('li.listing_id', 'item_listings.id')->where(function ($match) use ($search, $pattern) {
                            $column = DB::connection()->getQueryGrammar()->wrap('i.item_json->name');
                            $match->whereRaw($column.' LIKE ? ESCAPE \'!\'', [$pattern]);
                            if (ctype_digit($search) && strlen($search) <= 10) $match->orWhere('i.template_id', (int) $search);
                        });
                });
            }
            if (!empty($filters['group'])) {
                $ids = $itemFilters->templateIds($filters);
                $q->whereExists(function ($items) use ($filters, $ids, $itemFilters, $search) {
                    $items->selectRaw('1')->from('item_listing_items as li')->join('nro_inventory_items as i','i.id','=','li.inventory_item_id')
                        ->whereColumn('li.listing_id','item_listings.id');
                    $items->where(function($templates) use ($ids,$filters,$itemFilters) {
                        $templates->whereIn('i.template_id',$ids);
                        if ($filters['group'] === 'other' && !isset($filters['itemId'])) $templates->orWhereNotIn('i.template_id',$itemFilters->knownIds());
                    });
                    if ($search !== '') {
                        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                        $items->where(function ($match) use ($search, $pattern) {
                            $column = DB::connection()->getQueryGrammar()->wrap('i.item_json->name');
                            $match->whereRaw($column.' LIKE ? ESCAPE \'!\'', [$pattern]);
                            if (ctype_digit($search) && strlen($search) <= 10) $match->orWhere('i.template_id', (int)$search);
                        });
                    }
                    // Every item-specific condition must match the SAME item in a combo.
                    if (isset($filters['minStars'])) $items->where('i.filter_stars','>=',(int)$filters['minStars']);
                    if (!empty($filters['stat'])) $items->where('i.filter_'.$filters['stat'],true);
                });
            }
            if (! empty($filters['bundle'])) $q->where(function ($count) use ($filters) {
                $count->from('item_listing_items')->selectRaw('COUNT(*)')->whereColumn('listing_id', 'item_listings.id');
            }, $filters['bundle'] === 'single' ? '=' : '>', 1);
            if (! empty($filters['minPrice'])) $q->where('price', '>=', (float) $filters['minPrice']);
            if (! empty($filters['maxPrice'])) $q->where('price', '<=', (float) $filters['maxPrice']);
            if (($filters['sort'] ?? null) === 'price_asc') $q->orderBy('price');
            elseif (($filters['sort'] ?? null) === 'price_desc') $q->orderByDesc('price');

            $page = $q->orderByDesc('id')->paginate(20);
            $payloads = $s->listings($page->items());
            return [
                'data' => collect($page->items())->map(fn ($i) => Arr::except($payloads[$i->id], ['description']))->values(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'filters' => $itemFilters->metadata(),
                'lastPage' => $page->lastPage(), 'total' => $page->total(), 'currentPage' => $page->currentPage(),
                'servers' => ApiCache::remember('public:nro-metadata','server-metadata',60,fn()=>DB::table('servers')->where('status',true)->get(['id','name','name_view']))
            ];
        });
        return response()->json($payload);
    }
    public function show(int $id, NroShopService $s)
    {
        $payload = ApiCache::remember(
            'public:nro-shop:listings',
            ApiCache::key('nro-shop:listing', $id, (string) \Illuminate\Support\Facades\Cache::get('nro-shop:visibility-version', '0')),
            120,
            function () use ($id, $s) {
                $l = DB::table('item_listings')->where('id', $id)->where('status', 'active')->whereIn('account_id', DB::table('nro_accounts')->select('id')->whereNull('deleted_at')->where('shop_hidden', false)->where('login_sale_blocked', false))->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('item_orders')->whereColumn('item_orders.listing_id', 'item_listings.id'))->first();
                abort_unless($l, 404);

                return ['data' => Arr::except($s->listing($l), ['description'])];
            }
        );

        return response()->json($payload);
    }
    public function purchase(Request $r, NroShopService $s)
    {
        $v = $r->validate(['listingId' => 'required|integer', 'recipientName' => ['nullable','string','max:50','regex:/^[\pL\pN_]+$/u'],
            'serverId' => 'required|integer|exists:servers,id', 'requestKey' => 'required|uuid']);
        return response()->json(['data' => $this->freshOrder($s->purchase($r->user(), $v['listingId'], $v['recipientName'] ?? '', $v['serverId'], $v['requestKey']))]);
    }
    public function orders(Request $r, NroShopService $s)
    {
        $ids = DB::table('item_orders')->where('buyer_id', $r->user()->id)->orderByDesc('id')->paginate(20);
        $payloads = $s->orders(collect($ids->items())->pluck('id'));
        return response()->json(['data' => collect($ids->items())->map(fn ($i) => $payloads[$i->id])->values(), 'lastPage' => $ids->lastPage()]);
    }
    public function cancel(Request $r, int $id, \App\Services\NroOrderRefund $refund, NroShopService $shop) {
        $refund->request($id,$r->user());
        return response()->json(['data'=>$this->freshOrder($id)])->header('Cache-Control','no-store');
    }
    public function receive(Request $r, int $id, \App\Services\NroReceivingService $receiving, NroShopService $shop)
    {
        $v = $r->validate(['requestKey' => 'required|uuid', 'mode' => 'required|in:manual,auto',
            'recipientName' => ['required_if:mode,manual','nullable','string','max:50','regex:/^[\pL\pN_]+$/u'],
            'username' => 'required_if:mode,auto|nullable|string|max:141', 'password' => 'required_if:mode,auto|nullable|string|max:64']);
        $receiving->start($r->user(), $id, $v);
        return response()->json(['data' => $this->freshOrder($id)])->header('Cache-Control', 'no-store');
    }
    private function freshOrder(int $id): array
    {
        [, $orders]=app(\App\Services\NroRealtimePublisher::class)->snapshot([$id]);
        return $orders[$id];
    }
}
