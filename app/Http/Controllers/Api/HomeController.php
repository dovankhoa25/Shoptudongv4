<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ServerInfoResource;
use App\Models\Server;
use App\Support\ApiCache;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Lấy thông tin giá của tất cả server cho popup trang chủ
     */
    public function getServerPrices()
    {
        return ApiCache::rememberJson(
            'public:server-prices',
            ApiCache::key('server-prices', 'all'),
            120,
            function () {
                $servers = Server::active()
                    ->with(['currentGoldPrice', 'currentGemPrice'])
                    ->withSum('activeGemBots as total_available_gems', 'gem_qty')
                    ->get();

                return [
                    'success' => true,
                    'data' => ServerInfoResource::collection($servers),
                    'message' => 'Lấy thông tin giá thành công'
                ];
            }
        );
    }

    /**
     * Lấy thông tin giá của 1 server cụ thể
     */
    public function getServerPriceById($serverId)
    {
        return ApiCache::rememberJson(
            'public:server-prices',
            ApiCache::key('server-price', (int) $serverId),
            120,
            function () use ($serverId) {
                $server = Server::active()
                    ->with(['currentGoldPrice', 'currentGemPrice'])
                    ->withSum('activeGemBots as total_available_gems', 'gem_qty')
                    ->findOrFail($serverId);

                return [
                    'success' => true,
                    'data' => new ServerInfoResource($server),
                    'message' => 'Lấy thông tin giá thành công'
                ];
            }
        );
    }

    /**
     * Display homepage
     */
    public function index()
    {
        $servers = Server::active()
            ->with(['currentGoldPrice', 'currentGemPrice'])
            ->withSum('activeGemBots as total_available_gems', 'gem_qty')
            ->get();

        return view('home', [
            'servers' => ServerInfoResource::collection($servers)
        ]);
    }
}
