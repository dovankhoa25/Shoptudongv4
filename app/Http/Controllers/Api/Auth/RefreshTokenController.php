<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\FirstPartyClientService;
use App\Services\FirstPartyTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefreshTokenController extends Controller
{
    public function __construct(
        private readonly FirstPartyClientService $clients,
        private readonly FirstPartyTokenService $tokens,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'uuid'],
            'refresh_token' => ['required', 'string'],
        ]);

        $this->clients->validate($request, $validated['client_id']);

        return response()->json(
            $this->tokens->refresh($validated['refresh_token']),
        );
    }
}
