<?php

namespace App\Services;

use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $botToken;
    protected string $chatId;

    public function __construct(?string $botToken = null, ?string $chatId = null)
    {
        $this->botToken = $botToken ?? config('services.telegram.bot_token');
        $this->chatId   = $chatId   ?? config('services.telegram.chat_id');
    }

    /**
     * Gửi tin nhắn ngay lập tức (không queue)
     */
    public function sendNow(string $message): bool
    {
        try {
            $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
            $response = Http::timeout(5)->post($url, [
                'chat_id'    => $this->chatId,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]);

            if (!$response->successful()) {
                Log::warning('Telegram API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gửi tin nhắn qua hàng chờ
     */
    public function sendQueue(string $message): void
    {
        try {
            dispatch(new SendTelegramMessageJob($message, $this->chatId))
                ->onQueue('telegram');
        } catch (\Throwable $e) {
            Log::error('Queue dispatch Telegram failed: ' . $e->getMessage());
        }
    }
}
