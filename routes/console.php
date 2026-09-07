<?php

use App\Models\ChatRealtimeSession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::command('stats:compute')->everyMinute();

// Schedule::command('stats:compute')->dailyAt('02:00');
Schedule::command('stats:compute')->everyThirtyMinutes();

// Schedule command xóa nick cũ chạy hàng ngày lúc 3:00 AM
Schedule::command('nicks:clean-old')->dailyAt('03:00');
Schedule::command('gold:cancel-stale')->everyMinute()->withoutOverlapping();
Schedule::call(function (): void {
    ChatRealtimeSession::query()
        ->where('expires_at', '<=', now()->subDay())
        ->orWhere('revoked_at', '<=', now()->subDay())
        ->delete();
})->name('chat-realtime:clean-sessions')->dailyAt('04:00')->withoutOverlapping();
