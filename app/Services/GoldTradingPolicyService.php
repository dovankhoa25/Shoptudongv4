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
            'gold_per_bar' => 37_000_000, 'note' => 'Bán cho NPC khi nhân vật trên 2 tỷ sức mạnh.'],
        'nrj' => ['label' => 'Thỏi vàng NRJ', 'gold_type' => 'bar', 'gold_bar_template_id' => 2032,
            'gold_per_bar' => 195_000_000, 'note' => 'Bán cho Santa, không yêu cầu sức mạnh.'],
    ];

    public static function barAsset(int $template): string
    {
        return GoldBarAsset::template($template) === GoldBarAsset::NRJ ? 'nrj' : 'standard';
    }

    public function publicOptions(Server $server): array
    {
        $policies = $server->goldTradingPolicies->keyBy('asset_type');

        return collect(self::ASSETS)->map(function (array $asset, string $type) use ($policies): array {
            $policy = $policies->get($type);

            return ['asset_type' => $type, ...$asset,
                // Pure-gold purchases require an explicit opt-in. Preserve existing imports.
                'sell_enabled' => $policy?->sell_enabled ?? $type !== 'pure',
                'import_enabled' => $policy?->import_enabled ?? true,
            ];
        })->values()->all();
    }

    public function assertSaleAllowed(int $serverId, string $asset): void
    {
        $policy = GoldTradingPolicy::where('server_id', $serverId)->where('asset_type', $asset)->first();
        if (! ($policy?->sell_enabled ?? $asset !== 'pure')) {
            throw ValidationException::withMessages([
                'gold_type' => 'Server này đang tạm ngừng bán '.self::ASSETS[$asset]['label'].'.',
            ]);
        }
    }

    public function assertImportAllowed(int $serverId, int $template, int $bars, int $pure): void
    {
        $asset = self::barAsset($template);
        $policies = GoldTradingPolicy::where('server_id', $serverId)->get()->keyBy('asset_type');
        foreach ([$asset => $bars, 'pure' => $pure] as $type => $quantity) {
            if ($quantity > 0 && ! ($policies->get($type)?->import_enabled ?? true)) {
                throw ValidationException::withMessages([
                    $type === 'pure' ? 'pure_gold_qty' : 'gold_bar_qty' =>
                        'Server này đang tạm ngừng nhập '.self::ASSETS[$type]['label'].'.',
                ]);
            }
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
