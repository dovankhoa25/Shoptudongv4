<?php
namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class NroListingStock
{
    public static function policy(): array
    {
        $json = DB::transactionLevel() > 0 ? DB::table('settings')->where('key','nro_sale_item_policy')->value('value') : Setting::get('nro_sale_item_policy','{}');
        $value = json_decode($json ?: '{}', true) ?: [];
        return ['enabled' => (bool) ($value['enabled'] ?? false), 'ids' => array_values(array_unique(array_map('intval', $value['ids'] ?? [])))];
    }

    public static function allows(int $templateId, ?array $policy = null): bool
    {
        $policy ??= self::policy();
        return !$policy['enabled'] || in_array($templateId, $policy['ids'], true);
    }

    public static function allocated(int $accountId, ?int $exceptListing = null): array
    {
        [$totals,$own] = self::allocationMaps([$accountId]);
        return self::allocationExcept($totals[$accountId] ?? [], $exceptListing ? ($own[$exceptListing] ?? []) : []);
    }

    /** Fixed allocations take priority. Paused listings retain their allocation.
     * Automatic counts are recomputed from stock minus holds, never snapshot deltas.
     */
    public static function allocationMaps(iterable $accountIds): array
    {
        $rows = DB::table('item_listing_items as li')->join('item_listings as l','l.id','=','li.listing_id')
            ->join('nro_inventory_items as i','i.id','=','li.inventory_item_id')
            ->whereIn('l.account_id',$accountIds)->whereIn('l.status',['active','paused'])
            ->orderByRaw("CASE WHEN l.stock_mode = 'auto' THEN 1 ELSE 0 END")->orderBy('l.id')
            ->get(['l.account_id','l.id as listing_id','l.stock_mode','l.packages_remaining','li.inventory_item_id','li.quantity','i.quantity as stock','i.reserved']);
        $totals=[]; $own=[];
        foreach ($rows->groupBy('listing_id') as $listingId=>$lines) {
            $first=$lines->first(); $accountId=(int)$first->account_id;
            $count=$first->stock_mode==='auto'
                ? $lines->min(fn($line)=>intdiv(max(0,(int)$line->stock-(int)$line->reserved-($totals[$accountId][$line->inventory_item_id] ?? 0)),max(1,(int)$line->quantity)))
                : (int)($first->packages_remaining ?? 1);
            foreach ($lines as $line) {
                $itemId=(int)$line->inventory_item_id; $qty=(int)$line->quantity*$count;
                $own[(int)$listingId][$itemId]=$qty;
                $totals[$accountId][$itemId]=($totals[$accountId][$itemId] ?? 0)+$qty;
            }
        }
        return [$totals,$own];
    }

    public static function assertNoAutomaticOverlap(int $accountId, array $itemIds, ?int $except = null): void
    {
        NroShopService::require(!DB::table('item_listings as l')->join('item_listing_items as li','li.listing_id','=','l.id')
            ->where('l.account_id',$accountId)->where('l.stock_mode','auto')->whereIn('l.status',['active','paused'])
            ->when($except,fn($q)=>$q->where('l.id','!=',$except))->whereIn('li.inventory_item_id',$itemIds)->exists(),
            'Vật phẩm đã thuộc tin tự động khác. Thu hồi tin đó trước khi phân lại đồ.');
    }

    /** Allocation seen by one listing: everything its account holds, minus that listing's own share. */
    public static function allocationExcept(array $accountTotals, array $ownShare): array
    {
        foreach ($ownShare as $itemId => $quantity) $accountTotals[$itemId] = ($accountTotals[$itemId] ?? 0) - $quantity;

        return $accountTotals;
    }

    public static function selectable(object $item, array $allocated): int
    {
        return max(0, (int) $item->quantity - (int) $item->reserved - (int) ($allocated[$item->id] ?? 0));
    }
}
