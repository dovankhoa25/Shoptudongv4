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
    public function index(Request $r, NroShopService $shop)
    {
        $can = fn (...$p) => collect($p)->contains(fn ($permission) => $r->user()->can($permission));
        $caps = ['accounts' => $can('nro-accounts.view','nro-accounts.manage','nicks.create','nicks.manage','item-listings.manage','nro-settings.manage'),
            'manageAccounts' => $can('nro-accounts.manage'), 'readAccountSnapshots' => $can('nro-accounts.view','nro-accounts.manage'), 'publishNick' => $can('nicks.create','nicks.manage'), 'editNick' => $can('nicks.manage'),
            'listings' => $can('item-listings.view','item-listings.manage'), 'manageListings' => $can('item-listings.manage'),
            'orders' => $can('item-orders.view','item-orders.reconcile'), 'reconcile' => $can('item-orders.reconcile'),
            'workers' => $can('nro-workers.manage'), 'settings' => $can('nro-settings.manage')];
        $q = NroAccount::whereNotNull('usage_type');
        if (!$r->user()->canViewAllAdminData()) $q->where('user_id', $r->user()->id);
        $ownedIds = (clone $q)->select('id');
        $stats = ['total' => (clone $q)->count(), 'nick' => (clone $q)->where('usage_type', 'nick')->count(),
            'warehouse' => (clone $q)->where('usage_type', 'warehouse')->count(),
            'attention' => (clone $q)->whereIn('publish_status', ['needs_attention', 'scan_failed', 'publish_failed'])->count()];
        $filters = $r->validate(['q' => 'nullable|string|max:100', 'usage' => 'nullable|in:nick,warehouse', 'server' => 'nullable|integer',
            'state' => 'nullable|in:waiting,published,attention,sold', 'page' => 'nullable|integer|min:1']);
        if (!empty($filters['server'])) $filters['server'] = (int) $filters['server'];
        if (!empty($filters['q'])) $q->where(fn ($s) => $s->where('account_name', 'like', '%'.$filters['q'].'%')->orWhere('character_name', 'like', '%'.$filters['q'].'%'));
        if (!empty($filters['usage'])) $q->where('usage_type', $filters['usage']);
        if (!empty($filters['server'])) $q->where('server_id', $filters['server']);
        if (($filters['state'] ?? '') === 'waiting') $q->where('publish_status', 'waiting_snapshot');
        if (($filters['state'] ?? '') === 'published') $q->whereIn('id', Nick::withoutUserOwnedScope()->where('status', 'not_sold')->select('game_account_id'));
        if (($filters['state'] ?? '') === 'attention') $q->whereIn('publish_status', ['needs_attention', 'scan_failed', 'publish_failed']);
        if (($filters['state'] ?? '') === 'sold') $q->where('status', 'sold');
        $page = $q->orderByDesc('id')->paginate(30);
        $accounts = $page->getCollection();
        $nicks = Nick::withoutUserOwnedScope()->with('category:id,name,slug,status')->whereIn('game_account_id', $accounts->pluck('id'))
            ->get(['id','game_account_id','category_id','status','price','description','snapshot_id'])->keyBy('game_account_id');
        $listingCounts = DB::table('item_listings')->whereIn('account_id', clone $ownedIds)
            ->selectRaw("account_id, COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->groupBy('account_id')->get()->keyBy('account_id');
        $categories = $r->user()->canViewAllAdminData() ? Category::query() : $r->user()->categories()->wherePivot('can_post', true);
        $listings = DB::table('item_listings')->whereIn('account_id', clone $ownedIds)->orderByDesc('id')->limit(100)->get();
        $orders = DB::table('item_orders')->whereIn('account_id', clone $ownedIds)->orderByDesc('id')->limit(50)->get();
        $caps['salePolicy'] = $r->user()->canViewAllAdminData() || $can('nro-sale-policy.manage');
        return Inertia::render('Admin/NroShop/Index', ['accountStats' => $caps['accounts'] ? $stats : null, 'accountFilters' => $filters,
            'accountPagination' => ['total' => $caps['accounts'] ? $page->total() : 0, 'current' => $page->currentPage(), 'pageSize' => $page->perPage()],
            'capabilities' => $caps, 'salePolicy' => NroListingStock::policy(),
            'shopUrl' => preg_match('~^https?://~i', config('nro-shop.frontend_url') ?? '') ? rtrim(config('nro-shop.frontend_url'), '/') : null,
            'servers' => DB::table('servers')->where('status', true)->get(['id','name','name_view']),
            'loginServers' => $caps['manageAccounts'] || $caps['settings'] ? DB::table('server_game_login')->get(['id','name']) : [],
            'accounts' => $caps['accounts'] ? $accounts->map(fn ($a) => [
            'id' => $a->id, 'account_name' => $a->account_name, 'server_index' => $a->server_index, 'usage_type' => $a->usage_type,
            'character_name' => $a->character_name, 'last_synced_at' => $a->last_synced_at, 'latest_snapshot_id' => $a->latest_snapshot_id,
            'server_id' => $a->server_id, 'server_game_id' => $a->server_game_id, 'delivery_map' => $a->delivery_map, 'delivery_zone' => $a->delivery_zone, 'wait_minutes' => $a->wait_minutes,
            'delivery_zone_mode' => $a->delivery_zone_mode,
            'publishStatus' => $a->publish_status, 'publishError' => $a->publish_error, 'publishConfig' => $a->publish_config,
            'status' => $a->status, 'nick' => $this->nickSummary($nicks->get($a->id)),
            'listingCounts' => $caps['listings'] ? ['total' => (int) ($listingCounts->get($a->id)?->total ?? 0), 'active' => (int) ($listingCounts->get($a->id)?->active ?? 0)] : null,
        ]) : [], 'categories' => $categories->where('template', 'default')->where('status', 'active')->get(['categories.id', 'categories.name']),
            'listings' => $caps['listings'] ? $listings->map(fn ($l) => [...$shop->listing($l), 'accountId' => $l->account_id]) : [], 'orders' => $caps['orders'] ? $orders->map(fn ($o) => $shop->order($o->id)) : [],
            'jobs' => $caps['manageAccounts'] || $caps['reconcile'] ? DB::table('nro_worker_jobs')->whereIn('account_id', clone $ownedIds)->orderByDesc('id')->limit(50)->get(['id', 'account_id', 'order_id', 'type', 'status', 'updated_at', 'result_json']) : [],
            'canReconcile' => $caps['reconcile'],
            'workerKeys' => $caps['workers'] ? DB::table('nro_worker_keys')->orderByDesc('id')->get(['id', 'name', 'last_used_at', 'revoked_at', 'accepts_delivery']) : []]);
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
        return response()->json(['id' => $account->id]);
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
        return response()->json(['publishConfig' => $a->publish_config, 'publishStatus' => $a->publish_status, 'publishError' => $a->publish_error, 'status' => $a->status, 'latestSnapshotId' => $a->latest_snapshot_id, 'nick' => $this->nickSummary($this->linkedNick($a->id)),
            'snapshot' => $snapshot ? ['data' => $snapshot->data_json, 'completeness' => $snapshot->completeness_json, 'summary' => $snapshot->summary_json] : null,
            'inventory' => DB::table('nro_inventory_items')->where('account_id', $id)->where('quantity', '>', 0)->orderBy('id')->get()->map(fn ($i) => [
                'id' => $i->id, 'quantity' => $i->quantity, 'reserved' => $i->reserved, 'listed' => (int) ($allocated[$i->id] ?? 0),
                'selectable' => NroListingStock::selectable($i, $allocated), 'sellable' => NroListingStock::allows((int) $i->template_id, $policy),
                'item' => json_decode($i->item_json, true), 'locations' => json_decode($i->locations_json, true)] )]);
    }
    public function salePolicy(Request $r)
    {
        abort_unless($r->user()->canViewAllAdminData() || $r->user()->can('nro-sale-policy.manage'), 403);
        $v = $r->validate(['enabled' => 'required|boolean', 'ids' => 'present|array|max:10000', 'ids.*' => 'required|integer|min:0|max:100000|distinct']);
        Setting::set('nro_sale_item_policy', json_encode(['enabled' => $v['enabled'], 'ids' => array_map('intval', $v['ids'])]));
        return response()->json(['ok' => true]);
    }
    public function password(Request $r, int $id)
    {
        $a = $this->account($r, $id); $v = $r->validate(['password' => 'required|string|max:64']);
        DB::transaction(function () use ($a, $v) {
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->status === 'active', 'Không được đổi mật khẩu acc đã bán hoặc ngừng hoạt động.');
            NroShopService::require(!DB::table('nro_worker_jobs')->where('account_id', $a->id)->whereIn('status', ['queued', 'processing', 'review'])->exists(), 'Chờ công việc tool kết thúc trước khi đổi thông tin đăng nhập.');
            $a->update(['game_password' => $v['password']]);
            Nick::withoutUserOwnedScope()->where('game_account_id', $a->id)->where('status', 'not_sold')->update(['account_password' => AccountEncrypt::encrypt($v['password'])]);
        });
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
            if ($a->auto_publish) $a->update(['publish_status' => 'waiting_snapshot', 'publish_error' => null]);
            DB::table('nro_worker_jobs')->insert(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        });
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
        NroShopService::require($nick->status !== 'sold' && $nick->account_name === $account->account_name, 'Nick phải chưa bán và khớp tài khoản.');
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
        return response()->json(['id' => $nick->id]);
    }
    public function accountListings(Request $r, int $id, NroShopService $shop)
    {
        $a = $this->account($r, $id);
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $listings = DB::table('item_listings')->where('account_id', $a->id)->orderByDesc('id')->paginate(20);
        return response()->json(['data' => $listings->getCollection()->map(fn ($l) => [...$shop->listing($l), 'accountId' => $a->id]),
            'total' => $listings->total(), 'page' => $listings->currentPage(), 'perPage' => $listings->perPage()]);
    }

    public function publishItems(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        $v = $r->validate(['title' => 'required|string|max:180', 'description' => 'nullable|string|max:10000', 'price' => 'required|integer|min:1|max:9999999999',
            'items' => 'required|array|min:1|max:20', 'items.*.id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000000000']);
        $listing = DB::transaction(function () use ($a, $v) {
            $a = NroAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();
            NroShopService::require($a->usage_type === 'warehouse', 'Chỉ acc kho được đăng bán đồ.');
            $snapshot = NroAccountSnapshot::find($a->latest_snapshot_id);
            NroShopService::require($a->status === 'active', 'Acc không còn hoạt động, không được tạo gói đồ.');
            NroShopService::require($snapshot && ($snapshot->completeness_json['bag'] ?? false) && ($snapshot->completeness_json['chest'] ?? false), 'Chưa lấy đủ hành trang và rương. Yêu cầu tool lấy lại dữ liệu trước khi tạo gói đồ.');
            $listing = DB::table('item_listings')->insertGetId(['user_id' => $a->user_id, 'account_id' => $a->id, 'title' => $v['title'], 'description' => $v['description'] ?? '', 'price' => $v['price'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $allocated = NroListingStock::allocated($a->id, $listing);
            foreach ($v['items'] as $line) {
                $item = DB::table('nro_inventory_items')->where('id', $line['id'])->where('account_id', $a->id)->lockForUpdate()->first();
                NroShopService::require($item && NroListingStock::selectable($item, $allocated) >= $line['quantity'], 'Món không thuộc acc hoặc không đủ số lượng sau khi trừ đồ trong gói đang đăng và đơn chưa nhận.');
                NroShopService::require(NroListingStock::allows((int) $item->template_id), 'ID vật phẩm không nằm trong danh sách được phép bán.');
                DB::table('item_listing_items')->insert(['listing_id' => $listing, 'inventory_item_id' => $item->id, 'quantity' => $line['quantity']]);
            }
            return $listing;
        });
        return response()->json(['id' => $listing]);
    }
    public function toggle(Request $r, int $id)
    {
        $v = $r->validate(['status' => 'required|in:active,paused']);
        $l = DB::table('item_listings')->where('id', $id)->first(); abort_unless($l, 404); $this->account($r, $l->account_id);
        DB::transaction(function () use ($l, $id, $v) {
            NroAccount::whereKey($l->account_id)->lockForUpdate()->firstOrFail();
            DB::table('item_listings')->where('id', $id)->lockForUpdate()->first();
            NroShopService::require(!DB::table('item_orders')->where('listing_id', $id)->exists(), 'Gói đã có người mua. Hãy tạo gói mới nếu muốn bán tiếp.');
            if ($v['status'] === 'active') {
                $allocated = NroListingStock::allocated($l->account_id, $id);
                foreach (DB::table('item_listing_items')->where('listing_id', $id)->get() as $line) {
                    $item = DB::table('nro_inventory_items')->where('id', $line->inventory_item_id)->lockForUpdate()->first();
                    NroShopService::require($item && NroListingStock::selectable($item, $allocated) >= $line->quantity, 'Không đủ tồn để đăng lại gói. Đồ đã được phân cho gói khác hoặc đơn chưa nhận.');
                    NroShopService::require(NroListingStock::allows((int) $item->template_id), 'Gói chứa ID vật phẩm không được phép bán.');
                }
            }
            DB::table('item_listings')->where('id', $id)->update(['status' => $v['status'], 'updated_at' => now()]);
        }, 3);
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
        return response()->json(['ok' => true]);
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
            DB::table('nro_worker_jobs')->where('id', $id)->update(['status' => 'completed', 'lease_token' => null, 'result_json' => json_encode(['resolution' => $v, 'actorId' => $r->user()->id]), 'updated_at' => now()]);
        });
        return response()->json(['ok' => true]);
    }
}
