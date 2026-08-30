<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardType;
use Illuminate\Http\JsonResponse;

class CardTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $cardTypes = CardType::query()
            ->where('status', true)
            ->orderBy('telco')
            ->get(['id', 'telco', 'discount_rate'])
            ->map(fn (CardType $cardType): array => [
                'id' => $cardType->id,
                'telco' => $cardType->telco,
                'discount_rate' => (float) $cardType->discount_rate,
            ]);

        return response()->json([
            'success' => true,
            'data' => $cardTypes,
        ]);
    }
}
