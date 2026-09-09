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
    public function index(Request $r, NroShopService $s)
    {
        $filters = $r->validate(['page' => 'sometimes|integer|min:1', 'server' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:100', 'bundle' => 'nullable|in:single,combo',
            'minPrice' => 'nullable|numeric|min:0|max:1000000000000',
            'maxPrice' => ['nullable','numeric','min:0','max:1000000000000', ...($r->filled('minPrice') ? ['gte:minPrice'] : [])],
            'sort' => 'nullable|in:newest,price_asc,price_desc']);
        $cachePayload = $filters;
        $cachePayload['q'] = trim((string) ($cachePayload['q'] ?? ''));
        if (($cachePayload['q'] ?? '') === '') unset($cachePayload['q']);
        ksort($cachePayload);
        $cacheKey = ApiCache::key('nro-shop:listings', 'v1', json_encode($cachePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $payload = ApiCache::remember('public:nro-shop:listings', $cacheKey, 60, function () use ($filters, $s) {
            $q = DB::table('item_listings')->where('status', 'active')->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('item_orders')->whereColumn('item_orders.listing_id', 'item_listings.id'));
            if (! empty($filters['server'])) $q->whereIn('account_id', DB::table('nro_accounts')->select('id')->where('server_id', (int) $filters['server']));
            $search = trim((string) ($filters['q'] ?? ''));
            if ($search !== '') {
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
            if (! empty($filters['bundle'])) $q->where(function ($count) use ($filters) {
                $count->from('item_listing_items')->selectRaw('COUNT(*)')->whereColumn('listing_id', 'item_listings.id');
            }, $filters['bundle'] === 'single' ? '=' : '>', 1);
            if (! empty($filters['minPrice'])) $q->where('price', '>=', (float) $filters['minPrice']);
            if (! empty($filters['maxPrice'])) $q->where('price', '<=', (float) $filters['maxPrice']);
            if (($filters['sort'] ?? null) === 'price_asc') $q->orderBy('price');
            elseif (($filters['sort'] ?? null) === 'price_desc') $q->orderByDesc('price');

            $page = $q->orderByDesc('id')->paginate(20);
            return [
                'data' => collect($page->items())->map(fn ($i) => Arr::except($s->listing($i), ['description'])),
                'lastPage' => $page->lastPage(), 'total' => $page->total(), 'currentPage' => $page->currentPage(),
                'servers' => DB::table('servers')->where('status', true)->get(['id','name','name_view'])
            ];
        });
        return response()->json($payload);
    }
    public function show(int $id, NroShopService $s)
    {
        $payload = ApiCache::remember(
            'public:nro-shop:listings',
            ApiCache::key('nro-shop:listing', $id),
            120,
            function () use ($id, $s) {
                $l = DB::table('item_listings')->where('id', $id)->where('status', 'active')->whereNotExists(fn ($orders) => $orders->selectRaw('1')->from('item_orders')->whereColumn('item_orders.listing_id', 'item_listings.id'))->first();
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
        return response()->json(['data' => $s->order($s->purchase($r->user(), $v['listingId'], $v['recipientName'] ?? '', $v['serverId'], $v['requestKey']))]);
    }
    public function orders(Request $r, NroShopService $s)
    {
        $ids = DB::table('item_orders')->where('buyer_id', $r->user()->id)->orderByDesc('id')->paginate(20);
        return response()->json(['data' => collect($ids->items())->map(fn ($i) => $s->order($i->id)), 'lastPage' => $ids->lastPage()]);
    }
    public function receive(Request $r, int $id, \App\Services\NroReceivingService $receiving, NroShopService $shop)
    {
        $v = $r->validate(['requestKey' => 'required|uuid', 'mode' => 'required|in:manual,auto',
            'recipientName' => ['required_if:mode,manual','nullable','string','max:50','regex:/^[\pL\pN_]+$/u'],
            'username' => 'required_if:mode,auto|nullable|string|max:141', 'password' => 'required_if:mode,auto|nullable|string|max:64']);
        $receiving->start($r->user(), $id, $v);
        return response()->json(['data' => $shop->order($id)])->header('Cache-Control', 'no-store');
    }
}
