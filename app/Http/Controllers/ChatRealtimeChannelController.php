<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatRealtimeChannelController extends Controller
{
    public function __invoke(Request $request, ChatRealtimeChannel $realtime): JsonResponse
    {
        $channel = $realtime->currentForRequest($request);

        abort_unless($channel, 403, 'No active realtime credential.');

        return response()->json([
            'data' => [
                'channel' => $channel,
            ],
        ]);
    }
}
