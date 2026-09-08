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

    public static function selectable(object $item, array $allocated): int
    {
        return max(0, (int) $item->quantity - (int) $item->reserved - (int) ($allocated[$item->id] ?? 0));
    }
}
