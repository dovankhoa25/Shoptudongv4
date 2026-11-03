<?php
namespace App\Services\TradingV3;

use App\Models\Bot;
use App\Services\GoldBarAsset;
use Illuminate\Support\Str;

/** Validate the already completed local game round, without a preceding web reservation. */
final class LocalGoldTransferReceipt
{
    public static function direction(array $request, array $terms, Bot $from, Bot $to): string
    {
        $intent = $request['evidence']['intent'] ?? [];
        $fromRoles = array_map('trim', explode(',', (string)($intent['from_roles'] ?? '')));
        $toRoles = array_map('trim', explode(',', (string)($intent['to_roles'] ?? '')));
        $storage = in_array('import_main', $fromRoles, true) && (bool)array_intersect($toRoles, ['import_sub', 'selling_sub']);
        $refill = (bool)array_intersect($fromRoles, ['import_sub', 'selling_sub']) && in_array('selling_main', $toRoles, true);
        $valid = ($intent['local_transfer'] ?? false) === true
            && ($request['evidence']['kind'] ?? null) === 'transfer' && !empty($request['evidence']['confirmed'])
            && ($intent['id'] ?? null) === $request['step_id'] && Str::isUuid($intent['id'] ?? '')
            && ($intent['transfer_id'] ?? null) === $terms['id']
            && (int)($request['server_id'] ?? 0) === (int)$terms['server_id']
            && (int)($intent['server_id'] ?? 0) === (int)$terms['server_id']
            && (int)$from->server_id === (int)$to->server_id
            && (int)($intent['from_bot_id'] ?? 0) === (int)$terms['from_bot_id']
            && (int)($intent['to_bot_id'] ?? 0) === (int)$terms['to_bot_id']
            && (int)($intent['gold_bar_template_id'] ?? 457) === $terms['gold_bar_template_id']
            && ($intent['bars_qty'] ?? null) === $terms['bars_qty'] && $terms['bars_qty'] > 0
            && $terms['bars_qty'] <= GoldBarAsset::roundLimit($terms['gold_bar_template_id'])
            && ($intent['pure_gold_qty'] ?? null) === 0 && $terms['pure_gold_qty'] === 0
            && ($storage || $refill) && !in_array('checker', $fromRoles, true) && !in_array('checker', $toRoles, true);
        foreach (['from' => $from, 'to' => $to] as $side => $bot) {
            $login = $bot->loginServer;
            $binding = $login ? strtoupper(hash('sha256', $bot->server_id.':'.$login->ip.':'.(int)$login->port.':'.mb_strtolower($bot->account_name))) : '';
            $valid = $valid && $binding !== '' && hash_equals($binding, (string)($intent[$side.'_binding'] ?? ''))
                && Str::isUuid($intent[$side.'_trade_session_id'] ?? '') && (int)($intent[$side.'_character_id'] ?? 0) > 0
                && empty($intent[$side.'_game_failed']) && empty($intent[$side.'_game_cancelled']);
        }
        $proofs = 0;
        foreach (['from' => 'to', 'to' => 'from'] as $side => $peer) if (array_key_exists($side.'_game_success', $intent)) {
            $proofs++; $proof = $intent[$side.'_game_success'];
            $valid = $valid && is_array($proof) && ($intent[$side.'_final_prepared'] ?? null) === true
                && ($proof['intent_id'] ?? null) === $intent['id']
                && ($proof['session_id'] ?? null) === $intent[$side.'_trade_session_id']
                && ($proof['character_id'] ?? null) === $intent[$side.'_character_id']
                && ($proof['partner_id'] ?? null) === $intent[$peer.'_character_id']
                && (int)($proof['gold_bar_template_id'] ?? 457) === $terms['gold_bar_template_id']
                && ($proof['bars_qty'] ?? null) === $terms['bars_qty'] && ($proof['pure_gold_qty'] ?? null) === 0
                && ($proof['outcome'] ?? null) === 'success' && ($proof['offer_verified'] ?? null) === true
                && is_string($proof['observed_at'] ?? null) && strtotime($proof['observed_at']) !== false;
        }
        if (!$proofs) {
            // The tool may recover an interrupted round from exact, fresh paired
            // inventory changes. Incidental pure-gold theft is not part of this proof.
            $field = GoldBarAsset::field($terms['gold_bar_template_id']);
            $valid = $valid && empty($intent['stock_snapshot_pending'])
                && ($intent['from_final_prepared'] ?? null) === true && ($intent['to_final_prepared'] ?? null) === true
                && isset($intent['from_bars'], $intent['to_bars'], $request['from_snapshot'][$field], $request['to_snapshot'][$field])
                && is_int($intent['from_bars']) && is_int($intent['to_bars'])
                && $intent['from_bars'] - $request['from_snapshot'][$field] === $terms['bars_qty']
                && $request['to_snapshot'][$field] - $intent['to_bars'] === $terms['bars_qty'];
        }
        abort_unless($valid, 422, 'Local transfer receipt must prove the exact completed game round and endpoint bindings.');
        return $storage ? 'storage' : 'refill';
    }
}
