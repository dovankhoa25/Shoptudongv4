<?php

namespace App\Services;

use App\Models\{Bot, GoldTradingPolicy, Server};
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Support\Collection;

/** Observed stock only. No pending order/reservation arithmetic belongs in this public projection. */
class RadarTradingService
{
    public const ENDPOINTS = [15 => ['dragon11.teamobi.com', 14446], 16 => ['dragonsuper.teamobi.com', 17001], 17 => ['dragonsuper3.teamobi.com', 17001]];

    public static function endpoint(string $host, int $port): bool {
        return in_array([strtolower(trim($host)), $port], self::ENDPOINTS, true);
    }
    public static function supportedServer(Server $server, ?Collection $bots = null): bool {
        $bots ??= $server->bots()->withoutChecker()->with('loginServer')->get();
        $bots=$bots->filter(fn(Bot $bot)=>!$bot->isChecker());
        if (self::endpoint((string)$server->ip, (int)$server->port)) {
            return $bots->every(fn(Bot $bot) => self::matchesServer($bot, $server));
        }
        return $bots->isNotEmpty() && $bots->every(fn(Bot $bot) => self::supportedBot($bot))
            && $bots->pluck('server_game_id')->unique()->count() === 1;
    }
    public static function supportedBot(Bot $bot): bool {
        $login = $bot->loginServer;
        return $login && isset(self::ENDPOINTS[(int)$bot->server_game_id])
            && [strtolower(trim((string)$login->ip)), (int)$login->port] === self::ENDPOINTS[(int)$bot->server_game_id];
    }
    public static function matchesServer(Bot $bot, Server $server): bool {
        return (int)$bot->server_id === (int)$server->id && self::supportedBot($bot)
            && (!self::endpoint((string)$server->ip, (int)$server->port)
                || self::ENDPOINTS[(int)$bot->server_game_id] === [strtolower(trim((string)$server->ip)), (int)$server->port]);
    }
    public static function assertBot(Bot $bot, int $template): void {
        if ($template === GoldBarAsset::RADAR) {
            abort_unless($bot->server?->gold_radar_enabled && self::matchesServer($bot, $bot->server), 422, 'Radar trading requires configured Super 1, 2 or 3.');
        }
    }
    public static function room(Bot $bot): int {
        if ($bot->bag_capacity === null || $bot->bag_free_slots === null) return 0;
        return max(0, min((int)$bot->bag_free_slots, (int)$bot->bag_capacity));
    }
    public function summary(Server $server, ?Collection $allBots = null): array {
        $allBots ??= $server->bots()->with('loginServer')->get();
        $allBots = $allBots->where('server_id', $server->id)->unique('id');
        $active = $allBots->filter(fn(Bot $bot) => $bot->status && !$bot->isChecker() && self::matchesServer($bot, $server));
        $sale = $active->filter(fn(Bot $bot) => (bool)array_intersect(GoldTradingService::types($bot), ['selling_main', 'selling_sub']));
        $imports = $active->filter(fn(Bot $bot) => (bool)array_intersect(GoldTradingService::types($bot), ['import_main', 'import_sub']));
        $stock = (int)$allBots->sum('radar_qty'); // Disabled inventory still counts against the admin inventory cap.
        $limit = $server->goldTradingPolicies->firstWhere('asset_type', 'radar')?->import_stock_limit_bars;
        $physical = (int)$imports->sum(fn(Bot $bot) => self::room($bot));
        return ['server_id' => (int)$server->id,
            'sell_qty' => $sale->contains(fn(Bot $bot) => in_array('selling_main', GoldTradingService::types($bot), true)) ? (int)$sale->sum('radar_qty') : 0,
            'import_room' => $imports->contains(fn(Bot $bot) => in_array('import_main', GoldTradingService::types($bot), true)) ? min($physical, $limit === null ? $physical : max(0, (int)$limit - $stock)) : 0,
            'physical_import_room' => $physical, 'stock_qty' => $stock,
            'unknown_capacity_bots' => $imports->filter(fn(Bot $bot) => $bot->bag_free_slots === null || $bot->bag_capacity === null)->count(),
            'last_synced_at' => $active->pluck('last_synced_at')->filter()->min()?->toIso8601String()];
    }
}
