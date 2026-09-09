<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardType;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;

class CardTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiCache::remember(
            'public:card-types',
            ApiCache::key('card-types'),
            600,
            function () {
                $cardTypes = CardType::query()
            ->where('status', true)
            ->orderBy('telco')
            ->get(['id', 'telco', 'discount_rate'])
            ->map(fn (CardType $cardType): array => [
                'id' => $cardType->id,
                'telco' => $cardType->telco,
                'discount_rate' => (float) $cardType->discount_rate,
            ]);

                return [
            'success' => true,
            'data' => $cardTypes,
                ];
            }
        );
    }
}
