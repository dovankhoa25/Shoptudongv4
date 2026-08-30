<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerGameLogin\ServerGameLoginResource;
use App\Models\Bot;
use App\Models\GemBot;
use App\Models\Server;
use App\Models\ServerGameLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class ServerGameLoginController extends Controller
{
    public function index(Request $request)
    {
        $servers = ServerGameLogin::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('ip', 'like', "%{$search}%")
                        ->orWhere('port', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/ServerGameLogins/Index', [
            'servers' => ServerGameLoginResource::collection($servers),
            'filters' => $request->only('search'),
        ]);
    }



    public function store(Request $request)
    {

        $request->validate([
            'name' => 'required|string|max:100|unique:server_game_login,name',
            'ip' => 'required|string|max:50|unique:server_game_login,ip',
            'port' => 'required|string|max:50',
        ]);

        ServerGameLogin::create([
            'name' => $request->name,
            'ip' => $request->ip,
            'port' => $request->port,
        ]);

        return Redirect::route('admin.server-game-logins.index')->with('success', 'Đã tạo tài khoản server game.');
    }



    public function update(Request $request, ServerGameLogin $server)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:server_game_login,name,' . $server->id,
            'ip' => 'required|string|max:50|unique:server_game_login,ip,' . $server->id,
            'port' => 'required|string|max:50',

        ]);

        $server->update([
            'name' => $request->name,
            'ip' => $request->ip,
            'port' => $request->port,
        ]);

        return Redirect::route('admin.server-game-logins.index')->with('success', 'Đã cập nhật tài khoản server game.');
    }

    public function destroy(ServerGameLogin $server)
    {
        $isInUse = Bot::query()->where('server_game_id', $server->id)->exists()
            || GemBot::query()->where('server_game_id', $server->id)->exists();

        if ($isInUse) {
            return back()->with('error', 'Không thể xóa tài khoản server đang được bot sử dụng.');
        }

        $server->delete();

        return Redirect::route('admin.server-game-logins.index')
            ->with('success', 'Đã xóa tài khoản server game.');
    }
}
