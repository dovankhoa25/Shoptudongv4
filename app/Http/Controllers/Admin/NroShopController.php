<?php
namespace App\Http\Controllers\Admin;

use App\Helpers\AccountEncrypt;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Nick;
use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use App\Services\NroShopService;
use App\Services\NroListingStock;
use App\Models\Setting;
use App\Services\NroNickAttributeService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class NroShopController extends Controller
{
    private function nickSummary(?Nick $nick): ?array
    {
        if (!$nick) return null;
        return ['id' => $nick->id, 'status' => $nick->status, 'price' => (string) $nick->price,
            'description' => $nick->description, 'categoryId' => $nick->category_id,
            'categoryName' => $nick->category?->name, 'categorySlug' => $nick->category?->slug,
            'categoryActive' => $nick->category?->status === 'active', 'snapshotId' => $nick->snapshot_id];
    }

    private function linkedNick(int $accountId): ?Nick
    {
        return Nick::withoutUserOwnedScope()->with('category:id,name,slug,status')->where('game_account_id', $accountId)->first();
    }

    private function account(Request $r, int $id): NroAccount
    {
        $a = NroAccount::whereKey($id)->whereNotNull('usage_type')->firstOrFail();
        abort_unless($r->user()->canViewAllAdminData() || $a->user_id === $r->user()->id, 404);
        return $a;
    }
    /** What the signed-in user may see; drives both the props and every tab endpoint below. */
    private function capabilities(Request $r): array
    {
        $can = fn (...$p) => collect($p)->contains(fn ($permission) => $r->user()->can($permission));

        return ['refund' => $r->user()->hasAnyRole(['admin','super-admin']), 'accounts' => $can('nro-accounts.view','nro-accounts.manage','nicks.create','nicks.manage','item-listings.manage','nro-settings.manage'),
            'manageAccounts' => $can('nro-accounts.manage'), 'readAccountSnapshots' => $can('nro-accounts.view','nro-accounts.manage'),
            'publishNick' => $can('nicks.create','nicks.manage'), 'editNick' => $can('nicks.manage'),
            'listings' => $can('item-listings.view','item-listings.manage'), 'manageListings' => $can('item-listings.manage'),
            'orders' => $can('item-orders.view','item-orders.reconcile'), 'reconcile' => $can('item-orders.reconcile'),
            'workers' => $can('nro-workers.manage'), 'settings' => $can('nro-settings.manage'),
            'salePolicy' => $r->user()->canViewAllAdminData() || $can('nro-sale-policy.manage')];
    }

    /** Subquery of the account ids this user is allowed to touch. */
    private function ownedAccountIds(Request $r)
    {
        $q = NroAccount::whereNotNull('usage_type');
        if (!$r->user()->canViewAllAdminData()) $q->where('user_id', $r->user()->id);

        return $q->select('id');
    }

    /** Storefront visibility never disables the account or changes purchased orders. */
    public function visibility(Request $r, int $id)
    {
        $this->account($r, $id);
        $v = $r->validate(['hidden' => 'required|boolean']);
        DB::transaction(function () use ($id, $v) {
            $a = NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->usage_type === 'warehouse', 'Chức năng này dành cho acc kho đồ.');
            $a->update(['shop_hidden' => $v['hidden']]);
        });
        // A new namespace prevents an in-flight old read from repopulating visible data.
        \Illuminate\Support\Facades\Cache::forever('nro-shop:visibility-version', (string) Str::uuid());
        ApiCache::clearGroup('public:nro-shop:listings');
        return response()->json(['hidden' => (bool) $v['hidden']]);
    }

    public function index(Request $r)
    {
        $caps = $this->capabilities($r);
        $q = NroAccount::whereNotNull('usage_type');
        if (!$r->user()->canViewAllAdminData()) $q->where('user_id', $r->user()->id);
        $ownedIds = (clone $q)->select('id');
        if($r->attributes->get('admin_live_props')===['accountStats']) return Inertia::render('Admin/NroShop/Index',['accountStats'=>$this->stats($r,$ownedIds)]);
        $filters = $r->validate(['q' => 'nullable|string|max:100', 'usage' => 'nullable|in:nick,warehouse', 'server' => 'nullable|integer',
            'state' => 'nullable|in:waiting,published,attention,sold,hidden', 'page' => 'nullable|integer|min:1']);
        if (!empty($filters['server'])) $filters['server'] = (int) $filters['server'];
        if (!empty($filters['q'])) $q->where(fn ($s) => $s->where('account_name', 'like', '%'.$filters['q'].'%')->orWhere('character_name', 'like', '%'.$filters['q'].'%'));
        if (!empty($filters['usage'])) $q->where('usage_type', $filters['usage']);
        if (!empty($filters['server'])) $q->where('server_id', $filters['server']);
        if (($filters['state'] ?? '') === 'hidden') $q->where('shop_hidden', true);
        if (($filters['state'] ?? '') === 'waiting') $q->where('publish_status', 'waiting_snapshot');
        if (($filters['state'] ?? '') === 'published') $q->whereIn('id', Nick::withoutUserOwnedScope()->where('status', 'not_sold')->select('game_account_id'));
        if (($filters['state'] ?? '') === 'attention') $q->where(fn($q)=>$q->where('login_sale_blocked',true)->orWhereIn('publish_status', ['needs_attention', 'scan_failed', 'publish_failed', 'login_blocked']));
        if (($filters['state'] ?? '') === 'sold') $q->where('status', 'sold');
        $page = $q->orderByDesc('login_sale_blocked')->orderByRaw("CASE WHEN publish_status = 'login_blocked' THEN 0 ELSE 1 END")->orderByDesc('id')->paginate(30);
        $accounts = $page->getCollection();
        $warehouseJobs = DB::table('nro_worker_jobs')->whereIn('account_id', $accounts->pluck('id'))->where('status', 'processing')->get(['account_id','worker_instance','lease_until'])->groupBy('account_id');
        $nicks = Nick::withoutUserOwnedScope()->with('category:id,name,slug,status')->whereIn('game_account_id', $accounts->pluck('id'))
            ->get(['id','game_account_id','category_id','status','price','description','snapshot_id'])->keyBy('game_account_id');
        $listingCounts = DB::table('item_listings')->whereIn('account_id', $accounts->pluck('id'))
            ->selectRaw("account_id, COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->groupBy('account_id')->get()->keyBy('account_id');
        $owners = DB::table('users')->whereIn('id', $accounts->pluck('user_id')->filter()->unique())->pluck('username', 'id');
        $categories = $r->user()->canViewAllAdminData() ? Category::query() : $r->user()->categories()->wherePivot('can_post', true);

        // Only the accounts tab is rendered server-side. Every other tab pulls its own paginated
        // endpoint when opened, so a page load (and the status poll) never builds all five at once.
        return Inertia::render('Admin/NroShop/Index', [
            // Always sent: every count is scoped to the caller's own accounts, and the tab labels
            // need real totals even for a user who can only see one tab.
            'accountStats' => $this->stats($r, $ownedIds), 'accountFilters' => $filters,
            'accountPagination' => ['total' => $caps['accounts'] ? $page->total() : 0, 'current' => $page->currentPage(), 'pageSize' => $page->perPage()],
            'capabilities' => $caps, 'salePolicy' => [...NroListingStock::policy(), 'groupOverrides' => \App\Services\NroItemFilters::overrides(), 'groups' => app(\App\Services\NroItemFilters::class)->metadata()['groups']],
            'shopUrl' => preg_match('~^https?://~i', config('nro-shop.frontend_url') ?? '') ? rtrim(config('nro-shop.frontend_url'), '/') : null,
            'servers' => DB::table('servers')->where('status', true)->get(['id','name','name_view']),
            'loginServers' => $caps['manageAccounts'] || $caps['settings'] ? DB::table('server_game_login')->get(['id','name']) : [],
            'accounts' => $caps['accounts'] ? $accounts->map(fn ($a) => [
                'deliveryActivity' => \App\Services\NroWarehouseActivity::publicPayload($a, $warehouseJobs->get($a->id) ?? collect()), 'ownerUsername' => $owners->get($a->user_id), 'id' => $a->id, 'account_name' => $a->account_name, 'server_index' => $a->server_index, 'usage_type' => $a->usage_type,
                'character_name' => $a->character_name, 'last_synced_at' => $a->last_synced_at, 'latest_snapshot_id' => $a->latest_snapshot_id,
                'server_id' => $a->server_id, 'server_game_id' => $a->server_game_id, 'delivery_map' => $a->delivery_map, 'delivery_zone' => $a->delivery_zone, 'wait_minutes' => $a->wait_minutes,
                'delivery_zone_mode' => $a->delivery_zone_mode,
                'snapshotFailures' => (int)$a->snapshot_failures, 'publishStatus' => $a->publish_status, 'publishError' => $a->publish_error, 'publishConfig' => $a->publish_config,
                'loginSaleBlocked' => (bool)$a->login_sale_blocked, 'shop_hidden' => $a->shop_hidden, 'status' => $a->status, 'nick' => $this->nickSummary($nicks->get($a->id)),
                'listingCounts' => $caps['listings'] ? ['total' => (int) ($listingCounts->get($a->id)?->total ?? 0), 'active' => (int) ($listingCounts->get($a->id)?->active ?? 0)] : null,
            ]) : [],
            'categories' => $categories->where('template', 'default')->where('status', 'active')->get(['categories.id', 'categories.name']),
            'canReconcile' => $caps['reconcile'],
        ]);
    }

    /** Counts for the header cards; also the payload the page polls instead of reloading everything. */
    private function stats(Request $r, $ownedIds): array
    {
        $accounts = NroAccount::whereNotNull('usage_type');
        if (!$r->user()->canViewAllAdminData()) $accounts->where('user_id', $r->user()->id);
        $byUsage = (clone $accounts)->selectRaw('usage_type, COUNT(*) as total')->groupBy('usage_type')->pluck('total', 'usage_type');
        $jobs = DB::table('nro_worker_jobs')->whereIn('account_id', clone $ownedIds)
            ->whereIn('status', ['queued', 'processing', 'review'])->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['total' => (int) $byUsage->sum(), 'nick' => (int) ($byUsage['nick'] ?? 0), 'warehouse' => (int) ($byUsage['warehouse'] ?? 0),
            'attention' => (clone $accounts)->where(fn($q)=>$q->where('login_sale_blocked',true)->orWhereIn('publish_status', ['needs_attention', 'scan_failed', 'publish_failed', 'login_blocked']))->count(),
            'reviewJobs' => (int) ($jobs['review'] ?? 0), 'activeJobs' => (int) (($jobs['queued'] ?? 0) + ($jobs['processing'] ?? 0)),
            'openOrders' => DB::table('item_orders')->whereIn('account_id', clone $ownedIds)->whereNotIn('status', ['completed', 'refunded'])->count(),
            // Real totals for the tab labels; the old page showed the size of a truncated list instead.
            'listings' => DB::table('item_listings')->whereIn('account_id', clone $ownedIds)->count(),
            'orders' => DB::table('item_orders')->whereIn('account_id', clone $ownedIds)->count(),
            'jobs' => DB::table('nro_worker_jobs')->whereIn('account_id', clone $ownedIds)->count(),
            'workerOnline' => DB::table('nro_worker_keys')->where('accepts_delivery', true)->whereNull('revoked_at')
                ->where('last_used_at', '>', now()->subSeconds(90))->exists()];
    }

    /**
     * Lightweight poll target: the header counts only, never the tab bodies.
     * The route's permission middleware already gates this to staff who can open the page,
     * and every count is scoped to the caller's own accounts.
     */
    public function status(Request $r)
    {
        return response()->json($this->stats($r, $this->ownedAccountIds($r)))->header('Cache-Control', 'no-store');
    }

    private function paged(\Illuminate\Contracts\Pagination\LengthAwarePaginator $page, $data)
    {
        return response()->json(['data' => $data, 'total' => $page->total(), 'page' => $page->currentPage(), 'perPage' => $page->perPage()])
            ->header('Cache-Control', 'no-store');
    }

    public function listingsIndex(Request $r, NroShopService $shop)
    {
        abort_unless($this->capabilities($r)['listings'], 403);
        $v = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,paused,sold,draft',
            'accountId' => 'nullable|integer', 'page' => 'nullable|integer|min:1']);
        $q = DB::table('item_listings')->whereIn('account_id', $this->ownedAccountIds($r));
        if (!empty($v['status'])) $q->where('status', $v['status']);
        if (!empty($v['accountId'])) $q->where('account_id', (int) $v['accountId']);
        if (!empty(trim($v['q'] ?? ''))) {
            $term = trim($v['q']);
            $q->where(function ($s) use ($term) {
                $s->where('title', 'like', '%'.$term.'%');
                if (ctype_digit($term) && strlen($term) <= 18) $s->orWhere('id', (int) $term);
            });
        }
        $page = $q->orderByDesc('id')->paginate(20);
        $rows = $page->getCollection();
        $payloads = $shop->listings($rows);
        $owners = DB::table('users')->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('username', 'id');
        $accountNames = DB::table('nro_accounts')->whereIn('id', $rows->pluck('account_id')->filter()->unique())->pluck('account_name', 'id');

        return $this->paged($page, $rows->map(fn ($l) => [...$payloads[$l->id], 'accountId' => $l->account_id,
            'accountName' => $accountNames[$l->account_id] ?? null, 'ownerUsername' => $owners[$l->user_id] ?? null])->values());
    }

    public function ordersIndex(Request $r, NroShopService $shop)
    {
        abort_unless($this->capabilities($r)['orders'], 403);
        $v = $r->validate(['q' => 'nullable|string|max:100', 'accountId' => 'nullable|integer', 'page' => 'nullable|integer|min:1',
            'status' => 'nullable|in:queued,awaiting_receipt,processing,review,completed,refunded,failed,expired']);
        $q = DB::table('item_orders')->whereIn('account_id', $this->ownedAccountIds($r));
        if (!empty($v['status'])) $q->where('status', $v['status']);
        if (!empty($v['accountId'])) $q->where('account_id', (int) $v['accountId']);
        if (!empty(trim($v['q'] ?? ''))) {
            $term = trim($v['q']);
            $q->where(function ($s) use ($term) {
                $s->where('title', 'like', '%'.$term.'%')->orWhere('recipient_name', 'like', '%'.$term.'%')
                    ->orWhereIn('buyer_id', DB::table('users')->select('id')->where('username', 'like', '%'.$term.'%'))
                    ->orWhereIn('seller_id', DB::table('users')->select('id')->where('username', 'like', '%'.$term.'%'))
                    ->orWhereIn('account_id', DB::table('nro_accounts')->select('id')->where('account_name', 'like', '%'.$term.'%')->orWhere('character_name', 'like', '%'.$term.'%'));
                if (ctype_digit($term) && strlen($term) <= 18) $s->orWhere('id', (int) $term);
            });
        }
        $page = $q->orderByDesc('id')->paginate(20);
        $payloads = $shop->orders($page->getCollection()->pluck('id'));

        $people = DB::table('users')->whereIn('id', $page->getCollection()->pluck('buyer_id')->merge($page->getCollection()->pluck('seller_id'))->merge($page->getCollection()->pluck('refund_actor_id'))->filter()->unique())->pluck('username', 'id');
        $warehouses = DB::table('nro_accounts')->whereIn('id', $page->getCollection()->pluck('account_id')->unique())->get(['id', 'account_name', 'character_name'])->keyBy('id');
        return $this->paged($page, $page->getCollection()->map(fn ($o) => [...$payloads[$o->id], 'accountId' => $o->account_id,
            'refundActor' => $people[$o->refund_actor_id] ?? null, 'refundNote' => $o->refund_note,
            'buyerUsername' => $people[$o->buyer_id] ?? null, 'ownerUsername' => $people[$o->seller_id] ?? null,
            'accountName' => $warehouses->get($o->account_id)?->account_name,
            'botName' => $warehouses->get($o->account_id)?->character_name])->values());
    }

    public function jobsIndex(Request $r, NroShopService $shop)
    {
        $caps = $this->capabilities($r);
        abort_unless($caps['manageAccounts'] || $caps['reconcile'], 403);
        $v = $r->validate(['status' => 'nullable|in:queued,processing,review,completed,failed,expired',
            'type' => 'nullable|in:snapshot,delivery', 'accountId' => 'nullable|integer', 'page' => 'nullable|integer|min:1']);
        $q = DB::table('nro_worker_jobs')->whereIn('account_id', $this->ownedAccountIds($r));
        if (!empty($v['status'])) $q->where('status', $v['status']);
        if (!empty($v['type'])) $q->where('type', $v['type']);
        if (!empty($v['accountId'])) $q->where('account_id', (int) $v['accountId']);
        $page = $q->orderByDesc('id')->paginate(20);
        $rows = $page->getCollection();
        // The reconcile form needs the order behind each job awaiting review, and only those.
        $reviewOrderIds = $rows->where('status', 'review')->pluck('order_id')->filter()->unique();
        $orders = $caps['reconcile'] && $reviewOrderIds->isNotEmpty() ? $shop->orders($reviewOrderIds) : [];
        $late = DB::table('nro_late_results')->whereIn('job_id',$rows->pluck('id'))->orderByDesc('id')->get()->groupBy('job_id');
        $accountNames = DB::table('nro_accounts')->whereIn('id', $rows->pluck('account_id')->filter()->unique())->pluck('account_name', 'id');

        return $this->paged($page, $rows->map(fn ($j) => ['id' => $j->id, 'account_id' => $j->account_id, 'order_id' => $j->order_id,
            'auditOrderId' => $j->audit_order_id, 'type' => $j->type, 'status' => $j->status, 'updated_at' => $j->updated_at, 'result_json' => $j->result_json,
            'lateResults' => ($late->get($j->id) ?? collect())->take(5)->map(fn($x)=>['kind'=>$x->kind,'at'=>$x->created_at,'data'=>json_decode($x->payload_json,true)])->values(),
            'accountName' => $accountNames[$j->account_id] ?? null, 'order' => $orders[$j->order_id] ?? null])->values());
    }

    public function workerKeys(Request $r)
    {
        abort_unless($this->capabilities($r)['workers'], 403);

        return response()->json(['data' => DB::table('nro_worker_keys')->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'revoked_at', 'accepts_delivery'])])->header('Cache-Control', 'no-store');
    }
    public function createKey(Request $r)
    {
        abort_unless($r->user()->can('nro-workers.manage'), 403);
        $v = $r->validate(['name' => 'required|string|max:100']); $token = 'nrow_'.Str::random(64);
        $id = DB::table('nro_worker_keys')->insertGetId(['name' => $v['name'], 'token_hash' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id, 'token' => $token])->header('Cache-Control', 'no-store');
    }
    public function revokeKey(Request $r, int $id)
    {
        abort_unless($r->user()->can('nro-workers.manage'), 403);
        DB::table('nro_worker_keys')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }
    public function store(Request $r, \App\Services\NroAccountRegistration $registration)
    {
        $account = $registration->create($r->user(), $r->all());
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['id' => $account->id]);
    }

    public function updateAccount(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        $v = $r->validate(['username' => 'required|string|max:141', 'password' => 'nullable|string|max:64',
            'serverId' => 'required|integer|exists:servers,id', 'serverGameId' => 'required|integer|exists:server_game_login,id']);
        $v['username'] = trim($v['username']);
        NroShopService::require($v['username'] !== '', 'Tài khoản không được để trống.');
        DB::transaction(function () use ($r, $id, $v) {
            $this->requireAvailableRegistration($v['username'], (int) $v['serverGameId'], $id);
            $a = NroAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->usage_type === 'nick' && $a->status === 'active', 'Chỉ sửa acc bán nick chưa bán, đang hoạt động.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id', $id)->whereIn('status', ['queued','processing','review'])->exists(), 'Chờ công việc tool hoặc đối soát kết thúc trước khi sửa acc.');
            $nick = Nick::withoutUserOwnedScope()->where('game_account_id', $id)->lockForUpdate()->first();
            if ($nick) {
                $this->checkLinkedNick($r, $a, $nick);
                NroShopService::require(in_array($nick->status, ['not_sold','deleted']), 'Tin đã có lịch sử bán/hoàn trả, không được sửa để mở lại.');
                $this->nickCategory($r, $nick->category_id);
            }
            $config = $a->publish_config ?? [];
            if ($nick) $config = array_merge($config, ['categoryId' => $nick->category_id, 'price' => (int) $nick->price, 'description' => $nick->description, 'attributeSelections' => [], 'resumeNickId' => $nick->id]);
            NroShopService::require(!empty($config['categoryId']), 'Cần cấu hình danh mục trước khi sửa và đăng lại.');
            $this->nickCategory($r, (int) $config['categoryId']);
            abort_unless($r->user()->can('nicks.manage') || (!$nick && $r->user()->can('nicks.create')), 403);
            $a->update(['account_name' => $v['username'], 'game_password' => $v['password'] ?? $a->game_password,
                'server' => 'login'.$v['serverGameId'], 'server_game_id' => $v['serverGameId'], 'server_id' => $v['serverId'],
                'latest_snapshot_id' => null, 'last_synced_at' => null, 'character_name' => null,
                'auto_publish' => true, 'publish_config' => $config, 'publish_status' => 'waiting_snapshot', 'publish_error' => null]);
            if ($nick) { $nick->forceFill(['status' => 'deleted', 'account_name' => $a->account_name, 'snapshot_id' => null])->save(); }
            DB::table('nro_worker_jobs')->insert(['account_id' => $id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }

    public function importAccounts(Request $r, \App\Services\NroAccountImport $import)
    {
        $v = $r->validate(['text' => 'required|string|max:1048576', 'mode' => 'required|in:preview,import']);
        return response()->json($import->run($r->user(), $v['text'], $v['mode'] === 'import'))->header('Cache-Control', 'no-store');
    }

    private function requireAvailableRegistration(string $username, int $serverGameId, ?int $exceptId = null): void
    {
        app(\App\Services\NroAccountRegistration::class)->available($username, $serverGameId, $exceptId);
    }
    public function detail(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        abort_unless($r->user()->can('nro-accounts.view') || $r->user()->can('nro-accounts.manage')
            || ($a->usage_type === 'nick' ? ($r->user()->can('nicks.create') || $r->user()->can('nicks.manage')) : $r->user()->can('item-listings.manage')), 403);
        $snapshot = NroAccountSnapshot::find($a->latest_snapshot_id);
        $allocated = NroListingStock::allocated($id); $policy = NroListingStock::policy();
        return response()->json(['publishConfig' => $a->publish_config, 'snapshotFailures' => (int)$a->snapshot_failures, 'publishStatus' => $a->publish_status, 'publishError' => $a->publish_error, 'status' => $a->status, 'latestSnapshotId' => $a->latest_snapshot_id, 'nick' => $this->nickSummary($this->linkedNick($a->id)),
            'snapshot' => $snapshot ? ['data' => $snapshot->data_json, 'completeness' => $snapshot->completeness_json, 'summary' => $snapshot->summary_json] : null,
            'inventory' => DB::table('nro_inventory_items')->where('account_id', $id)->where('quantity', '>', 0)->orderBy('id')->get()->map(fn ($i) => [
                'id' => $i->id, 'quantity' => $i->quantity, 'reserved' => $i->reserved, 'listed' => (int) ($allocated[$i->id] ?? 0),
                'selectable' => NroListingStock::selectable($i, $allocated), 'sellable' => NroListingStock::allows((int) $i->template_id, $policy),
                'item' => json_decode($i->item_json, true), 'locations' => json_decode($i->locations_json, true)] )]);
    }
    public function salePolicy(Request $r)
    {
        abort_unless($r->user()->canViewAllAdminData() || $r->user()->can('nro-sale-policy.manage'), 403);
        $v = $r->validate(['enabled' => 'required|boolean', 'ids' => 'present|array|max:10000', 'ids.*' => 'required|integer|min:0|max:100000|distinct',
            'groupOverrides'=>'sometimes|array|max:3000', 'groupOverrides.*.id'=>'required|integer|min:0|max:100000|distinct',
            'groupOverrides.*.group'=>'required|in:'.implode(',',array_keys(\App\Services\NroItemFilters::GROUPS))]);
        if (array_key_exists('groupOverrides',$v)) {
            $known = app(\App\Services\NroItemFilters::class)->knownIds();
            foreach ($v['groupOverrides'] as $row) NroShopService::require(in_array((int)$row['id'],$known,true),'ID phân nhóm không có trong catalog: '.$row['id']);
            Setting::set('nro_item_group_overrides',json_encode(array_map(fn($row)=>['id'=>(int)$row['id'],'group'=>$row['group']],$v['groupOverrides'])));
        }
        Setting::set('nro_sale_item_policy', json_encode(['enabled' => $v['enabled'], 'ids' => array_map('intval', $v['ids'])]));
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }
    public function password(Request $r, int $id)
    {
        $a = $this->account($r, $id); $v = $r->validate(['password' => 'required|string|max:64','confirmedStopped'=>'sometimes|boolean']);
        DB::transaction(function () use ($a, $v) {
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->status === 'active', 'Không được đổi mật khẩu acc đã bán hoặc ngừng hoạt động.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$a->id)->where(function($q) use($v) {
                $q->whereIn('status',['queued','processing'])->orWhere('lease_until','>=',now());
                if(!($v['confirmedStopped'] ?? false)) $q->orWhere('status','review');
            })->exists(),'Dừng phiên tool và chờ quyền giữ phiên hết hạn. Nếu còn đối soát, xác nhận đã dừng phiên cũ trước khi sửa mật khẩu.');
            $updates = ['game_password' => $v['password'], 'snapshot_failures'=>0];
            if ($a->publish_status === 'login_blocked') {
                $updates['publish_status'] = $a->usage_type === 'nick' && $a->auto_publish ? 'waiting_snapshot' : null;
                $updates['publish_error'] = null;
            }
            if ($a->login_sale_blocked) {
                $updates['last_synced_at']=null;
                $updates['publish_status']='waiting_snapshot';
                $updates['publish_error']='Đã cập nhật mật khẩu; đang kiểm tra đăng nhập và dữ liệu kho.';
                DB::table('nro_worker_jobs')->insert(['account_id'=>$a->id,'type'=>'snapshot','status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
            }
            $a->update($updates);
            Nick::withoutUserOwnedScope()->where('game_account_id', $a->id)->where('status', 'not_sold')->update(['account_password' => AccountEncrypt::encrypt($v['password'])]);
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }
    public function scan(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        DB::transaction(function () use ($a) {
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->status === 'active', 'Acc đã bán hoặc ngừng hoạt động, không được lấy dữ liệu.');
            NroShopService::require(!Nick::withoutUserOwnedScope()->where('game_account_id', $a->id)->where('status', 'sold')->exists(), 'Nick đã bán, không được đăng nhập lại.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id', $a->id)->whereIn('status', ['queued', 'processing', 'review'])->exists(), 'Acc đã có công việc hoặc đang chờ đối soát.');
            $a->update(['snapshot_failures' => 0]);
            $a->update(['publish_status' => $a->auto_publish ? 'waiting_snapshot' : null, 'publish_error' => null]);
            DB::table('nro_worker_jobs')->insert(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }
    private function nickCategory(Request $r, int $id): Category
    {
        $category = Category::findOrFail($id);
        abort_unless($category->template === 'default' && $category->status === 'active' && ($r->user()->canViewAllAdminData() || $r->user()->categories()->where('categories.id', $category->id)->wherePivot('can_post', true)->exists()), 403);
        return $category;
    }

    private function checkLinkedNick(Request $r, NroAccount $account, Nick $nick): void
    {
        abort_unless($r->user()->can('nicks.manage'), 403);
        abort_unless($nick->user_id === $account->user_id && ($r->user()->canViewAllAdminData() || $nick->user_id === $r->user()->id), 403);
        NroShopService::require(in_array($nick->status, ['not_sold', 'deleted']) && $nick->account_name === $account->account_name, 'Nick phải chưa bán và khớp tài khoản.');
    }

    public function draftNickAttributes(Request $r, NroNickAttributeService $attributes)
    {
        $v = $r->validate(['categoryId' => 'required|integer|exists:categories,id', 'serverId' => 'nullable|integer|exists:servers,id']);
        return response()->json($attributes->preview(new NroAccount(['server_id' => $v['serverId'] ?? null]), $this->nickCategory($r, $v['categoryId'])));
    }

    public function nickAttributes(Request $r, int $id, NroNickAttributeService $attributes)
    {
        $a = $this->account($r, $id);
        $v = $r->validate(['categoryId' => 'required|integer|exists:categories,id', 'nickId' => 'nullable|integer']);
        $category = $this->nickCategory($r, $v['categoryId']);
        NroShopService::require($a->usage_type === 'nick' && (bool) $a->latest_snapshot_id, 'Cần acc bán nick đã lấy snapshot.');
        $nick = isset($v['nickId']) ? Nick::withoutUserOwnedScope()->findOrFail($v['nickId']) : null;
        if ($nick) $this->checkLinkedNick($r, $a, $nick);
        return response()->json($attributes->preview($a, $category, $nick))->header('Cache-Control', 'no-store');
    }

    public function publishNick(Request $r, int $id, NroNickAttributeService $attributes)
    {
        $a = $this->account($r, $id);
        $v = $r->validate(['categoryId' => 'required|integer|exists:categories,id', 'price' => 'required|integer|min:1|max:9999999999', 'description' => 'nullable|string|max:10000', 'nickId' => 'nullable|integer',
            'snapshotId' => 'sometimes|required|integer', 'attributeSelections' => 'sometimes|array|max:100', 'attributeSelections.*' => 'nullable|integer']);
        $category = $this->nickCategory($r, $v['categoryId']);
        $nick = DB::transaction(function () use ($a, $v, $r, $category, $attributes) {
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->usage_type === 'nick' && (bool) $a->latest_snapshot_id, 'Cần acc bán nick đã lấy snapshot.');
            NroShopService::require($a->status === 'active', 'Acc đã bán hoặc ngừng hoạt động, không được đăng bán.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id', $a->id)->whereIn('status', ['queued', 'processing', 'review'])->exists(), 'Chờ tool xử lý xong trước khi đăng nick.');
            $nick = isset($v['nickId']) ? Nick::withoutUserOwnedScope()->whereKey($v['nickId'])->lockForUpdate()->firstOrFail() : new Nick;
            if ($nick->exists) {
                $this->checkLinkedNick($r, $a, $nick);
            }
            $duplicates = Nick::withoutUserOwnedScope()->where('game_account_id', $a->id);
            if ($nick->exists) $duplicates->where('id', '!=', $nick->id);
            NroShopService::require(!$duplicates->exists(), 'Acc đã liên kết với tin bán khác.');
            $preview = $attributes->preview($a, $category, $nick->exists ? $nick : null);
            NroShopService::require(!isset($v['snapshotId']) || (int) $v['snapshotId'] === (int) $preview['snapshotId'], 'Snapshot đã thay đổi. Mở lại form để kiểm tra thuộc tính trước khi đăng.');
            $nick->forceFill(['game_account_id' => $a->id, 'snapshot_id' => $a->latest_snapshot_id, 'user_id' => $a->user_id,
                'category_id' => $v['categoryId'], 'account_name' => $a->account_name, 'account_password' => AccountEncrypt::encrypt($a->game_password),
                'price' => $v['price'], 'description' => $v['description'] ?? '', 'status' => 'not_sold', 'listing_type' => $nick->listing_type ?? 'normal']);
            $nick->save();
            $attributes->sync($nick, $preview, $v['attributeSelections'] ?? null);
            app(\App\Services\NroAutoPublishService::class)->attachImages($a, $nick);
            $a->update(['auto_publish' => false, 'publish_status' => 'published', 'publish_error' => null]);
            return $nick;
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['id' => $nick->id]);
    }
    public function accountListings(Request $r, int $id, NroShopService $shop)
    {
        $a = $this->account($r, $id);
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $listings = DB::table('item_listings')->where('account_id', $a->id)->orderByDesc('id')->paginate(20);
        $payloads = $shop->listings($listings->getCollection());
        $ownerUsername = \App\Models\User::whereKey($a->user_id)->value('username');
        return response()->json(['data' => $listings->getCollection()->map(fn ($l) => [...$payloads[$l->id], 'accountId' => $a->id, 'accountName' => $a->account_name, 'ownerUsername' => $ownerUsername])->values(),
            'total' => $listings->total(), 'page' => $listings->currentPage(), 'perPage' => $listings->perPage()]);
    }

    public function publishItems(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        $v = $r->validate(['title' => 'nullable|string|max:180', 'description' => 'nullable|string|max:10000', 'price' => 'required|integer|min:1|max:9999999999',
            'items' => 'required|array|min:1|max:20', 'items.*.id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000000000']);
        $listing = DB::transaction(function () use ($a, $v) {
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->usage_type === 'warehouse', 'Chỉ acc kho được đăng bán đồ.');
            $snapshot = NroAccountSnapshot::find($a->latest_snapshot_id);
            NroShopService::require($a->status === 'active', 'Acc không còn hoạt động, không được tạo gói đồ.');
            NroShopService::require($snapshot && ($snapshot->completeness_json['bag'] ?? false) && ($snapshot->completeness_json['chest'] ?? false) && ($snapshot->completeness_json['equipped'] ?? false) && $a->last_synced_at, 'Chưa lấy đủ hành trang, rương và trang bị. Yêu cầu tool lấy lại dữ liệu trước khi tạo gói đồ.');
            $title = trim((string) ($v['title'] ?? ''));
            $itemNames = [];
            $listing = DB::table('item_listings')->insertGetId(['user_id' => $a->user_id, 'account_id' => $a->id, 'title' => $title, 'description' => $v['description'] ?? '', 'price' => $v['price'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $allocated = NroListingStock::allocated($a->id, $listing);
            foreach ($v['items'] as $line) {
                $item = DB::table('nro_inventory_items')->where('id', $line['id'])->where('account_id', $a->id)->lockForUpdate()->first();
                NroShopService::require($item && NroListingStock::selectable($item, $allocated) >= $line['quantity'], 'Món không thuộc acc hoặc không đủ số lượng sau khi trừ đồ trong gói đang đăng và đơn chưa nhận.');
                NroShopService::require(NroListingStock::allows((int) $item->template_id), 'ID vật phẩm không nằm trong danh sách được phép bán.');
                $itemData = json_decode($item->item_json, true) ?: [];
                $itemNames[] = trim((string) ($itemData['name'] ?? '')) ?: 'Vật phẩm #'.$item->template_id;
                DB::table('item_listing_items')->insert(['listing_id' => $listing, 'inventory_item_id' => $item->id, 'quantity' => $line['quantity']]);
            }
            if ($title === '') {
                DB::table('item_listings')->where('id', $listing)->update(['title' => Str::limit(implode(', ', array_unique($itemNames)), 180, '')]);
            }
            return $listing;
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['id' => $listing]);
    }
    public function toggle(Request $r, int $id)
    {
        $v = $r->validate(['status' => 'sometimes|required|in:active,paused', 'price' => 'sometimes|required|integer|min:1|max:9999999999']);
        NroShopService::require(count($v) > 0, 'Chọn giá hoặc trạng thái cần sửa.');
        $l = DB::table('item_listings')->where('id', $id)->first(); abort_unless($l, 404); $this->account($r, $l->account_id);
        DB::transaction(function () use ($l, $id, $v) {
            NroAccount::whereKey($l->account_id)->lockForUpdate()->firstOrFail();
            DB::table('item_listings')->where('id', $id)->lockForUpdate()->first();
            NroShopService::require(!DB::table('item_orders')->where('listing_id', $id)->exists(), 'Gói đã có người mua. Hãy tạo gói mới nếu muốn bán tiếp.');
            if (($v['status'] ?? null) === 'active') {
                $allocated = NroListingStock::allocated($l->account_id, $id);
                foreach (DB::table('item_listing_items')->where('listing_id', $id)->get() as $line) {
                    $item = DB::table('nro_inventory_items')->where('id', $line->inventory_item_id)->lockForUpdate()->first();
                    NroShopService::require($item && NroListingStock::selectable($item, $allocated) >= $line->quantity, 'Không đủ tồn để đăng lại gói. Đồ đã được phân cho gói khác hoặc đơn chưa nhận.');
                    NroShopService::require(NroListingStock::allows((int) $item->template_id), 'Gói chứa ID vật phẩm không được phép bán.');
                }
            }
            DB::table('item_listings')->where('id', $id)->update([...$v, 'updated_at' => now()]);
        }, 3);
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }
    public function settings(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        $v = $r->validate(['server_id' => 'required|integer|exists:servers,id', 'server_game_id' => 'required|integer|exists:server_game_login,id',
            'delivery_zone_mode' => 'sometimes|required|in:auto,fixed',
            'delivery_map' => 'required|integer|min:0|max:255', 'delivery_zone' => 'required|integer|min:0|max:255', 'wait_minutes' => 'required|integer|min:1|max:60']);
        DB::transaction(function () use ($a, $v) {
            $this->requireAvailableRegistration($a->account_name, (int) $v['server_game_id'], $a->id);
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->status === 'active', 'Acc đã bán hoặc ngừng hoạt động, không được đổi cấu hình.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id', $a->id)->whereIn('status', ['queued','processing','review'])->exists(), 'Chờ tool kết thúc trước khi đổi cấu hình.');
            if ($a->server_id != $v['server_id'] || $a->server_game_id != $v['server_game_id']) {
                NroShopService::require(!DB::table('item_orders')->where('account_id', $a->id)->whereNotIn('status', ['completed','refunded'])->exists(), 'Kho có đơn chưa giao; không được đổi server.');
                $v['last_synced_at'] = null;
            }
            $a->update([...$v, 'server' => 'login'.$v['server_game_id']]);
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }
    public function stockCheck(Request $r, int $id, \App\Services\NroOrderStockCheck $checks)
    {
        abort_unless($r->user()->can('item-orders.reconcile') || ($r->user()->can('nro-accounts.manage') && $r->user()->can('item-orders.view')),403);
        $o=DB::table('item_orders')->find($id); abort_unless($o,404);
        $this->account($r,$o->account_id);
        if($r->isMethod('post')) {
            $r->validate(['confirmedStopped'=>'required|accepted']);
            $checks->request($id,$r->user());
        } elseif($r->isMethod('delete')) {
            $r->validate(['confirmedStopped'=>'required|accepted']);
            $checks->cancel($id,$r->user());
        }
        return response()->json($checks->report($id))->header('Cache-Control','no-store');
    }

    public function refund(Request $r, int $id, \App\Services\NroOrderRefund $refund)
    {
        abort_unless($r->user()->hasAnyRole(['admin','super-admin']), 403);
        $v=$r->validate(['amount'=>'required|integer|min:1','note'=>'required|string|min:10|max:250']);
        $refund->run($id, $r->user(), (int)$v['amount'], $v['note']);
        return response()->json(['ok'=>true]);
    }

    public function reconcile(Request $r, int $id, NroShopService $shop)
    {
        abort_unless($r->user()->can('item-orders.reconcile'), 403);
        $v = $r->validate(['resolution' => 'required|in:delivered,not_delivered', 'note' => 'required|string|min:10|max:250',
            'items' => 'nullable|array|max:20', 'items.*.id' => 'required|integer|distinct', 'items.*.delivered' => 'required|integer|min:0']);
        DB::transaction(function () use ($id, $v, $shop, $r) {
            $aId = DB::table('nro_worker_jobs')->where('id', $id)->value('account_id'); abort_unless($aId, 404);
            $this->account($r, $aId);
            NroAccount::whereKey($aId)->lockForUpdate()->firstOrFail();
            $job = DB::table('nro_worker_jobs')->where('id', $id)->lockForUpdate()->first();
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id',$aId)->whereNotNull('audit_order_id')->whereIn('status',['queued','processing'])->exists(), 'Chờ tool kiểm tra kho xong trước khi chốt đối soát.');
            NroShopService::require($job->status === 'review', 'Chỉ đối soát công việc đang chờ kiểm tra.');
            NroShopService::require(!$job->lease_until || $job->lease_until < now()->toDateTimeString(), 'Chờ lease tool hết hạn và dừng tool trước khi đối soát.');
            foreach ($v['items'] ?? [] as $line) {
                $item = DB::table('item_order_items')->where('order_id', $job->order_id)->where('id', $line['id'])->lockForUpdate()->first();
                NroShopService::require($item && $line['delivered'] >= $item->delivered && $line['delivered'] <= $item->quantity, 'Số lượng đối soát không hợp lệ.');
                $delta = $line['delivered'] - $item->delivered;
                $res = DB::table('item_inventory_reservations')->where('order_id', $job->order_id)->where('inventory_item_id', $item->inventory_item_id)->lockForUpdate()->first();
                NroShopService::require($res && $res->status === 'held' && $res->quantity >= $delta, 'Phần đồ giữ không khớp.');
                DB::table('item_inventory_reservations')->where('id', $res->id)->decrement('quantity', $delta);
                DB::table('nro_inventory_items')->where('id', $item->inventory_item_id)->decrement('reserved', $delta);
                DB::table('item_order_items')->where('id', $item->id)->update(['delivered' => $line['delivered']]);
                NroAccount::whereKey($aId)->update(['last_synced_at' => null]);
            }
            if ($job->order_id) {
                if ($v['resolution'] === 'delivered' || !DB::table('item_order_items')->where('order_id', $job->order_id)->whereColumn('delivered', '<', 'quantity')->exists()) $shop->settle($job->order_id, true);
                else DB::table('item_orders')->where('id', $job->order_id)->update(['status' => 'awaiting_receipt', 'delivery_message' => 'Đã đối soát; bạn có thể nhận phần đồ còn lại.', 'updated_at' => now()]);
            }
            app(\App\Services\NroReceivingService::class)->finish($job, $job->order_id && DB::table('item_orders')->where('id', $job->order_id)->value('status') === 'completed' ? 'completed' : 'failed');
            DB::table('nro_worker_jobs')->where('id', $id)->update(['status' => 'completed', 'result_json' => json_encode(['resolution' => $v, 'actorId' => $r->user()->id]), 'updated_at' => now()]);
        });
        ApiCache::clearGroups(['public:nick', 'public:nro-shop:listings']);
        return response()->json(['ok' => true]);
    }

}
