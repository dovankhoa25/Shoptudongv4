<?php

use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Log;

if (!function_exists('sendTelegram')) {
    /**
     * Gửi tin nhắn Telegram qua hàng chờ
     */
    function sendTelegram(string $message, ?string $chatId = null): void
    {
        $chatId ??= config('services.telegram.chat_id');

        try {
            dispatch(new SendTelegramMessageJob($message, $chatId))
                ->onQueue('telegram');
        } catch (\Throwable $e) {
            Log::error('Queue dispatch Telegram failed: ' . $e->getMessage());
        }
    }
}
