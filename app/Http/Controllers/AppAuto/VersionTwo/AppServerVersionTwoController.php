<?php

namespace App\Http\Controllers\AppAuto\VersionTwo;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\VersionTwo\AppServerVersionTwoResource;
use App\Http\Resources\AppAuto\VersionTwo\AppServerGameLoginVersionTwoResource;
use App\Models\Server;
use App\Models\ServerGameLogin;
use Illuminate\Http\Request;

class AppServerVersionTwoController extends Controller
{
    public function index(Request $request)
    {
        // Lấy toàn bộ server + gold_prices đang active
        $servers = Server::with(['goldPrices' => function ($query) {
            $query->where('status', true);
        }])->get();


        return AppServerVersionTwoResource::collection($servers)
            ->additional([
                'success' => true,
            ]);
    }

    public function login(Request $request)
    {
        // Lấy toàn bộ server + gold_prices đang active
        $server_login = ServerGameLogin::query()

            ->get();
        return AppServerGameLoginVersionTwoResource::collection($server_login)
            ->additional([
                'success' => true,
            ]);
    }
}
