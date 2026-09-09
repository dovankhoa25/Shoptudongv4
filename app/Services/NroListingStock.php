<?php
namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class NroListingStock
{
    public static function policy(): array
    {
        $value = json_decode(Setting::get('nro_sale_item_policy', '{}'), true) ?: [];
        return ['enabled' => (bool) ($value['enabled'] ?? false), 'ids' => array_values(array_unique(array_map('intval', $value['ids'] ?? [])))];
    }

    public static function allows(int $templateId, ?array $policy = null): bool
    {
        $policy ??= self::policy();
        return !$policy['enabled'] || in_array($templateId, $policy['ids'], true);
    }

    // Active unsold listings allocate stock; purchased listings are represented by order reservations instead.
    public static function allocated(int $accountId, ?int $exceptListing = null): array
    {
        return DB::table('item_listing_items as li')->join('item_listings as l', 'l.id', '=', 'li.listing_id')
            ->where('l.account_id', $accountId)->where('l.status', 'active')
            ->when($exceptListing !== null, fn ($q) => $q->where('l.id', '!=', $exceptListing))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_orders')->whereColumn('item_orders.listing_id', 'l.id'))
            ->groupBy('li.inventory_item_id')->selectRaw('li.inventory_item_id, SUM(li.quantity) as allocated')
            ->pluck('allocated', 'inventory_item_id')->all();
    }

    /**
     * Batch counterpart of allocated(): totals per account, plus each listing's own share so a
     * caller can subtract the listing it is describing instead of running one query per listing.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, int>>}
     */
    public static function allocationMaps(iterable $accountIds): array
    {
        $rows = DB::table('item_listing_items as li')->join('item_listings as l', 'l.id', '=', 'li.listing_id')
            ->whereIn('l.account_id', $accountIds)->where('l.status', 'active')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_orders')->whereColumn('item_orders.listing_id', 'l.id'))
            ->get(['l.account_id', 'li.listing_id', 'li.inventory_item_id', 'li.quantity']);

        $totals = []; $own = [];
        foreach ($rows as $row) {
            $totals[(int) $row->account_id][(int) $row->inventory_item_id] = ($totals[(int) $row->account_id][(int) $row->inventory_item_id] ?? 0) + (int) $row->quantity;
            $own[(int) $row->listing_id][(int) $row->inventory_item_id] = ($own[(int) $row->listing_id][(int) $row->inventory_item_id] ?? 0) + (int) $row->quantity;
        }

        return [$totals, $own];
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
