<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Profile\ListCardHistoryRequest;
use App\Http\Resources\Profile\CardHistoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CardHistoryController extends Controller
{
    public function index(ListCardHistoryRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $cards = $request->user()
            ->cards()
            ->with('cardType:id,telco')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('serial', 'like', "%{$search}%")
                        ->orWhere('trans_id', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return CardHistoryResource::collection($cards);
    }
}
