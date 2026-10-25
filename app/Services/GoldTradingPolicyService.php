<?php

namespace App\Services;

use App\Models\{Bot, GoldTradingPolicy, Server};
use Illuminate\Validation\ValidationException;

/** Website admission only. Existing orders and tool execution keep their original terms. */
class GoldTradingPolicyService
{
    public const ASSETS = [
        'pure' => ['label' => 'Vàng tươi', 'gold_type' => 'pure', 'gold_bar_template_id' => null,
            'gold_per_bar' => null, 'note' => 'Giao dịch vàng tươi trực tiếp trên server hỗ trợ.'],
        'standard' => ['label' => 'Thỏi vàng thường', 'gold_type' => 'bar', 'gold_bar_template_id' => 457,
            'gold_per_bar' => 37_000_000, 'note' => 'Bán cho Santa khi nhân vật trên 2 tỷ sức mạnh.'],
        'nrj' => ['label' => 'Thỏi vàng NRJ', 'gold_type' => 'bar', 'gold_bar_template_id' => 2032,
            'gold_per_bar' => 195_000_000, 'note' => 'Bán cho Santa, không yêu cầu sức mạnh.'],
        'radar' => ['label' => 'Rada cấp 12', 'gold_type' => 'bar', 'gold_bar_template_id' => 281,
            'gold_per_bar' => 37_500_000, 'note' => '1 rada = 37,5 triệu vàng. Giao dịch trực tiếp với bot.'],
    ];

    public static function barAsset(int $template): string
    {
        return match(GoldBarAsset::template($template)) {GoldBarAsset::NRJ => 'nrj', GoldBarAsset::RADAR => 'radar', default => 'standard'};
    }

    public function publicOptions(Server $server): array
    {
        $policies = $server->goldTradingPolicies->keyBy('asset_type');

        return collect(self::ASSETS)->filter(fn($asset, $type) => $server->gold_radar_enabled ? $type === 'radar' : $type !== 'radar')->map(function (array $asset, string $type) use ($policies): array {
            $policy = $policies->get($type);

            return ['asset_type' => $type, ...$asset,
                // Pure-gold purchases require an explicit opt-in. Preserve existing imports.
                'sell_enabled' => $policy?->sell_enabled ?? !in_array($type, ['pure', 'radar'], true),
                'import_enabled' => $policy?->import_enabled ?? $type !== 'radar',
            ];
        })->values()->all();
    }

    public function assertSaleAllowed(int $serverId, string $asset): void
    {
        $server = Server::findOrFail($serverId);
        if (($asset === 'radar') !== (bool)$server->gold_radar_enabled) {
            throw ValidationException::withMessages(['gold_type' => 'Loại vàng này không được giao dịch trên server đã chọn.']);
        }
        if ($asset === 'radar' && !RadarTradingService::supportedServer($server)) throw ValidationException::withMessages(['gold_type'=>'Server chưa cấu hình đúng endpoint Super 1–3.']);
        $policy = GoldTradingPolicy::where('server_id', $serverId)->where('asset_type', $asset)->first();
        if (! ($policy?->sell_enabled ?? !in_array($asset, ['pure', 'radar'], true))) {
            throw ValidationException::withMessages([
                'gold_type' => 'Server này đang tạm ngừng bán '.self::ASSETS[$asset]['label'].'.',
            ]);
        }
    }

    public function assertRadarSaleQuantity(int $serverId, int $quantity): void {
        $available = app(RadarTradingService::class)->summary(Server::findOrFail($serverId))['sell_qty'];
        if ($quantity > $available) throw ValidationException::withMessages(['money_amount' => 'Bot chính và phụ hiện còn '.$available.' rada. Vui lòng giảm số lượng.']);
    }

    public function assertImportAllowed(int $serverId, int $template, int $bars, int $pure): void
    {
        $asset = self::barAsset($template);
        $server = Server::findOrFail($serverId);
        if ($server->gold_radar_enabled ? ($template !== 281 || $pure > 0) : $template === 281) {
            throw ValidationException::withMessages(['gold_bar_qty' => 'Loại vàng này không được giao dịch trên server đã chọn.']);
        }
        if ($asset === 'radar' && !RadarTradingService::supportedServer($server)) throw ValidationException::withMessages(['gold_bar_qty'=>'Server chưa cấu hình đúng endpoint Super 1–3.']);
        $policies = GoldTradingPolicy::where('server_id', $serverId)->get()->keyBy('asset_type');
        foreach ([$asset => $bars, 'pure' => $pure] as $type => $quantity) {
            if ($quantity > 0 && ! ($policies->get($type)?->import_enabled ?? $type !== 'radar')) {
                throw ValidationException::withMessages([
                    $type === 'pure' ? 'pure_gold_qty' : 'gold_bar_qty' =>
                        'Server này đang tạm ngừng nhập '.self::ASSETS[$type]['label'].'.',
                ]);
            }
        }

        if ($asset === 'radar' && $bars > app(RadarTradingService::class)->summary($server)['import_room']) {
            throw ValidationException::withMessages(['gold_bar_qty' => 'Bot chính và phụ hiện chỉ còn chỗ nhận thêm '.app(RadarTradingService::class)->summary($server)['import_room'].' rada.']);
        }

        $limit = $policies->get($asset)?->import_stock_limit_bars;
        if ($bars <= 0 || $limit === null) return;

        // Deliberately count every bot on this server, including disabled bots.
        // Do not reserve pending imports or subtract pending purchases.
        $stock = (int) Bot::where('server_id', $serverId)->sum(GoldBarAsset::field($template));
        $room = max(0, $limit - $stock);
        if ($bars > $room) {
            $label = self::ASSETS[$asset]['label'];
            $message = $room > 0
                ? 'Server này hiện chỉ nhận thêm tối đa '.number_format($room, 0, ',', '.').' '.$label.'. Vui lòng giảm số lượng.'
                : 'Server này đã đạt giới hạn tồn '.$label.'. Vui lòng quay lại sau.';
            throw ValidationException::withMessages(['gold_bar_qty' => $message]);
        }
    }
}
