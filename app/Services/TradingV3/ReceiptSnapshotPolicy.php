<?php

namespace App\Services\TradingV3;

use App\Models\{GemTransaction, GoldTransaction};

final class ReceiptSnapshotPolicy
{
    public static function gemPending(array $d, GemTransaction $order): bool
    {
        $intent = $d['evidence']['intent'] ?? [];
        if (empty($intent['stock_snapshot_pending'])) return false;
        $proof = is_array($intent['server_success'] ?? null) ? $intent['server_success'] : [];
        abort_unless(self::matches($proof, $intent)
            && ($intent['id'] ?? null) === $d['step_id']
            && (int)($intent['order_id'] ?? 0) === $order->id
            && ($intent['kind'] ?? null) === $d['evidence']['kind']
            && ($proof['kind'] ?? null) === $intent['kind']
            && (int)($proof['character_id'] ?? 0) > 0
            && ($proof['character_id'] ?? null) === ($intent['character_id'] ?? null)
            && ($proof['recipient_name'] ?? null) === $order->character_name
            && isset($intent['amount']) && ($proof['amount'] ?? null) === $intent['amount']
            && in_array($proof['source'] ?? null, ['game_message', 'received_item', 'inventory_delta'], true),
            422, 'A pending inventory snapshot requires the current gem step success proof.');
        return true;
    }

    public static function goldPending(array $d, ?GoldTransaction $order = null): bool
    {
        $intent = $d['evidence']['intent'] ?? [];
        if (empty($intent['stock_snapshot_pending'])) return false;
        if ($order) {
            $proof = is_array($intent['game_success'] ?? null) ? $intent['game_success'] : [];
            $valid = self::goldProof($proof, $intent, $d, 'trade_session_id', 'character_id', 'customer_id')
                && ($intent['id'] ?? null) === $d['step_id']
                && (int)($intent['order_id'] ?? 0) === $order->id
                && (int)($proof['partner_id'] ?? 0) === (int)$order->character_id;
        } else {
            $from = is_array($intent['from_game_success'] ?? null) ? $intent['from_game_success'] : [];
            $to = is_array($intent['to_game_success'] ?? null) ? $intent['to_game_success'] : [];
            // A server success at either endpoint confirms the same completed trade.
            // Native recovery also checks the other endpoint before emitting its receipt.
            $fromValid = self::goldProof($from, $intent, $d, 'from_trade_session_id', 'from_character_id', 'to_character_id');
            $toValid = self::goldProof($to, $intent, $d, 'to_trade_session_id', 'to_character_id', 'from_character_id');
            $valid = ($intent['id'] ?? null) === $d['step_id'] && ($fromValid || $toValid)
                && (!$from || $fromValid) && (!$to || $toValid)
                && empty($intent['from_game_cancelled']) && empty($intent['to_game_cancelled']);
        }
        abort_unless($valid, 422, 'A pending inventory snapshot requires the current gold step success proof.');
        return true;
    }

    private static function goldProof(array $proof, array $intent, array $d, string $sessionField, string $characterField, string $partnerField): bool
    {
        return self::matches($proof, $intent)
            && !empty($proof['session_id'])
            && ($proof['session_id'] ?? null) === ($intent[$sessionField] ?? null)
            && ($proof['offer_verified'] ?? null) === true
            && (int)($proof['character_id'] ?? 0) > 0
            && (int)($proof['partner_id'] ?? 0) > 0
            && ($proof['character_id'] ?? null) === ($intent[$characterField] ?? null)
            && ($proof['partner_id'] ?? null) === ($intent[$partnerField] ?? null)
            && (int)($proof['gold_bar_template_id'] ?? 457) === (int)($d['gold_bar_template_id'] ?? 457)
            && (int)($intent['gold_bar_template_id'] ?? 457) === (int)($d['gold_bar_template_id'] ?? 457)
            && ($proof['bars_qty'] ?? null) === $d['bars_qty']
            && ($proof['pure_gold_qty'] ?? null) === ($d['pure_gold_qty'] ?? 0);
    }

    private static function matches(array $proof, array $intent): bool
    {
        return !empty($intent['id']) && ($proof['intent_id'] ?? null) === $intent['id']
            && ($proof['outcome'] ?? null) === 'success' && !empty($proof['observed_at']);
    }
}
