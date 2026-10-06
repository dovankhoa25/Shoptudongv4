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
        [$filters, $clearedFilters] = app(\App\Services\NroListingFilters::class)->normalize($r, $itemFilters);
        $cachePayload = $filters;
        $cachePayload['q'] = trim((string) ($cachePayload['q'] ?? ''));
        if (($cachePayload['q'] ?? '') === '') unset($cachePayload['q']);
        ksort($cachePayload);
        $cacheKey = ApiCache::key('nro-shop:listings', 'filters-v8-stock', $itemFilters->version(), (string) \Illuminate\Support\Facades\Cache::get('nro-shop:visibility-version', '0'), json_encode($cachePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $payload = ApiCache::remember('public:nro-shop:listings', $cacheKey, 60, function () use ($filters, $s, $itemFilters) {
            $q = DB::table('item_listings')->where('status', 'active')->whereIn('account_id', DB::table('nro_accounts')->select('id')->whereNull('deleted_at')->where('shop_hidden', false)->where('login_sale_blocked', false))->where('policy_blocked', false);
            app(\App\Services\NroListingFilters::class)->apply($q, $filters, $itemFilters);

            $q->whereIntegerInRaw('item_listings.id', \App\Services\NroListingStock::inStockListingIds());
            $page = $q->orderByDesc('id')->paginate(20);
            $payloads = $s->listings($page->items());
            return [
                'data' => collect($page->items())->map(fn ($i) => $this->publicListing($payloads[$i->id]))->values(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'filters' => $itemFilters->metadata(),
                'lastPage' => $page->lastPage(), 'total' => $page->total(), 'currentPage' => $page->currentPage(),
                'servers' => ApiCache::remember('public:nro-metadata','server-metadata',60,fn()=>DB::table('servers')->where('status',true)->get(['id','name','name_view']))
            ];
        });
        return response()->json([...$payload, 'clearedFilters'=>$clearedFilters]);
    }
    public function show(int $id, NroShopService $s)
    {
        $payload = ApiCache::remember(
            'public:nro-shop:listings',
            ApiCache::key('nro-shop:listing', $id, (string) \Illuminate\Support\Facades\Cache::get('nro-shop:visibility-version', '0')),
            120,
            function () use ($id, $s) {
                $l = DB::table('item_listings')->where('id', $id)->where('status', 'active')->whereIn('account_id', DB::table('nro_accounts')->select('id')->whereNull('deleted_at')->where('shop_hidden', false)->where('login_sale_blocked', false))->where('policy_blocked', false)->first();
                abort_unless($l, 404);

                return ['data' => $this->publicListing($s->listing($l))];
            }
        );

        return response()->json($payload);
    }
    private function publicListing(array $data): array
    {
        $public = $data['publicDescription'] ?? null;
        return [...Arr::only($data, ['id','title','shopHidden','price','status','lastOrderStatus','serverIndex','serverId','serverName',
            'stockMode','packagesRemaining','policyBlocked','quantityEnabled','available','stockAvailable','unavailableReasons','workerOnline','needsSync','items']),
            ...($public !== null && $public !== '' ? ['description'=>$public] : [])];
    }
    public function purchase(Request $r, NroShopService $s)
    {
        $v = $r->validate(['listingId' => 'required|integer', 'recipientName' => ['nullable','string','max:50','regex:/^[\pL\pN_]+$/u'],
            'packageQuantity' => 'sometimes|integer|min:1|max:1000000', 'expectedPrice' => 'sometimes|integer|min:1|max:9999999999',
            'serverId' => 'required|integer|exists:servers,id', 'requestKey' => 'required|uuid']);
        return response()->json(['data' => $this->freshOrder($s->purchase($r->user(), $v['listingId'], $v['recipientName'] ?? '', $v['serverId'], $v['requestKey'], $v['packageQuantity'] ?? 1, $v['expectedPrice'] ?? null))]);
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
