<?php

namespace App\Services\TradingV3;

use App\Models\Bot;
use Illuminate\Support\Str;

/** A correlated final game failure releases a reservation, without altering inventory. */
final class GoldTransferFailureEvidence
{
    public static function confirmed(array $request, array $terms, Bot $from, Bot $to): bool
    {
        $intent = $request['evidence']['intent'] ?? [];
        if (!is_array($intent) || (!array_key_exists('from_game_failed', $intent)
            && !array_key_exists('to_game_failed', $intent))) return false;
        $valid = ($intent['id'] ?? null) === $request['step_id'] && Str::isUuid($intent['id'] ?? '')
            && ($intent['transfer_id'] ?? null) === $terms['id']
            && (int)($request['server_id'] ?? 0) === (int)$terms['server_id']
            && (int)($intent['server_id'] ?? 0) === (int)$terms['server_id']
            && (int)$from->server_id === (int)$to->server_id
            && (int)($intent['from_bot_id'] ?? 0) === (int)$terms['from_bot_id']
            && (int)($intent['to_bot_id'] ?? 0) === (int)$terms['to_bot_id']
            && (int)($intent['gold_bar_template_id'] ?? 457) === (int)$terms['gold_bar_template_id']
            && ($intent['bars_qty'] ?? null) === $terms['bars_qty'] && $terms['bars_qty'] > 0
            && ($intent['pure_gold_qty'] ?? null) === 0 && $terms['pure_gold_qty'] === 0
            && empty($intent['game_success']) && empty($intent['from_game_success']) && empty($intent['to_game_success']);
        foreach (['from' => $from, 'to' => $to] as $side => $bot) {
            $login = $bot->loginServer;
            $binding = $login ? strtoupper(hash('sha256', $bot->server_id.':'.$login->ip.':'.(int)$login->port.':'.mb_strtolower($bot->account_name))) : '';
            $valid = $valid && $binding !== '' && hash_equals($binding, (string)($intent[$side.'_binding'] ?? ''));
            if (array_key_exists($side.'_game_failed', $intent)) {
                $valid = $valid && self::proof($intent[$side.'_game_failed'], $intent, $side);
            }
        }
        abort_unless($valid, 422, 'Transfer failure must identify the exact reserved game round without success evidence.');
        return true;
    }

    private static function proof(mixed $proof, array $intent, string $side): bool
    {
        if (!is_array($proof)) return false;
        $peer = $side === 'from' ? 'to' : 'from';
        return ($intent[$side.'_final_prepared'] ?? null) === true
            && ($proof['intent_id'] ?? null) === $intent['id']
            && Str::isUuid($proof['session_id'] ?? '')
            && ($proof['session_id'] ?? null) === ($intent[$side.'_trade_session_id'] ?? null)
            && (int)($proof['character_id'] ?? 0) > 0 && (int)($proof['partner_id'] ?? 0) > 0
            && ($proof['character_id'] ?? null) === ($intent[$side.'_character_id'] ?? null)
            && ($proof['partner_id'] ?? null) === ($intent[$peer.'_character_id'] ?? null)
            && (int)($proof['gold_bar_template_id'] ?? 457) === (int)($intent['gold_bar_template_id'] ?? 457)
            && ($proof['bars_qty'] ?? null) === $intent['bars_qty'] && ($proof['pure_gold_qty'] ?? null) === 0
            && ($proof['outcome'] ?? null) === 'failed' && ($proof['offer_verified'] ?? null) === true
            && is_string($proof['observed_at'] ?? null) && strtotime($proof['observed_at']) !== false;
    }
}
