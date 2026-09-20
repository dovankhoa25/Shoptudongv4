<?php
namespace App\Services;
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;
final class NroSellerPolicy
{
    public static function rows(iterable $userIds): array
    {
        return DB::table('nro_seller_policies')->whereIn('user_id', $userIds)->get()->keyBy('user_id')->all();
    }
    public static function read(int $userId): ?object
    {
        // Deliberately uncached: purchase authorization must use committed policy.
        return DB::table('nro_seller_policies')->where('user_id', $userId)->first();
    }
    public static function allows(array $item, ?object $seller, ?array $global = null): bool
    {
        $id = (int) ($item['templateId'] ?? -1);
        if ((int) ($item['type'] ?? -1) === 5 || !NroListingStock::allows($id, $global)) return false;
        if (!$seller) return true;
        if (!$seller->selling_enabled) return false;
        $allow = json_decode($seller->allow_ids ?? '[]', true) ?: [];
        $deny = json_decode($seller->deny_ids ?? '[]', true) ?: [];
        return !in_array($id, $deny, true) && (!$allow || in_array($id, $allow, true));
    }
    public static function stackable(array $item): bool
    {
        return isset($item['type']) && !in_array((int) $item['type'], [0, 1, 2, 3, 4, 5], true);
    }
    public static function refreshAccounts(iterable $accountIds, ?array $global = null): void
    {
        $listings = DB::table('item_listings')->whereIn('account_id', $accountIds)->get(['id','user_id','policy_blocked']);
        $policies = self::rows($listings->pluck('user_id')->unique());
        $lines = DB::table('item_listing_items as li')->join('nro_inventory_items as i','i.id','=','li.inventory_item_id')
            ->whereIn('li.listing_id',$listings->pluck('id'))->get(['li.listing_id','i.item_json'])->groupBy('listing_id');
        $global ??= NroListingStock::policy();
        $changed = [0=>[], 1=>[]];
        foreach ($listings as $listing) {
            $blocked = ($lines->get($listing->id) ?? collect())->contains(fn ($line) => !self::allows(json_decode($line->item_json,true) ?: [], $policies[$listing->user_id] ?? null, $global));
            if ((bool)$listing->policy_blocked !== $blocked) $changed[(int)$blocked][] = $listing->id;
        }
        foreach ($changed as $blocked=>$ids) {
            foreach (array_chunk($ids,500) as $chunk) DB::table('item_listings')->whereIn('id',$chunk)->update(['policy_blocked'=>(bool)$blocked]);
        }
        ApiCache::clearGroup('public:nro-shop:listings');
    }
}
