<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class NextJsWebhook
{
    public static function invalidate(string $type, string $action, array $data = []): void
    {
        $payload = array_merge([
            'type'      => $type,
            'action'    => $action,
            'timestamp' => time(),
        ], $data);

        $json = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $json, config('services.nextjs_webhook.secret'));

        Http::withHeaders([
            'X-Webhook-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->post(config('services.nextjs_webhook.url') . '/api/webhooks/invalidate', $payload);
    }
}
