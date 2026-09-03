<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Server\ServerResource;
use App\Models\Server;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class ServerController extends Controller
{
    public function index(Request $request)
    {
        $query = Server::query()->latest();
        AdminTableSearch::applyPreset($query, $request->input('search'), 'servers');
        $servers = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Servers/Index', [
            'roles' => ServerResource::collection($servers),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {

        $request->validate([
            'name' => 'required|string|max:255|unique:servers,name',
            'name_view' => 'required|string|max:255|unique:servers,name_view',
            'ip' => 'nullable|string|max:255',
            'port' => 'nullable|string|max:255',
            'status' => 'required|boolean',
        ]);

        Server::create([
            'name' => $request->name,
            'name_view' => $request->name_view,
            'ip' => $request->ip,
            'port' => $request->port,
            'status' => $request->status,
        ]);

        return Redirect::route('admin.servers.index')->with('success', 'Role created.');
    }

    public function update(Request $request, Server $server)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:servers,name,'.$server->id,
            'name_view' => 'required|string|max:255|unique:servers,name_view,'.$server->id,
            'ip' => 'nullable|string|max:255',
            'port' => 'nullable|string|max:255',
            'status' => 'required|boolean',
        ]);

        $server->update([
            'name' => $request->name,
            'name_view' => $request->name_view,
            'ip' => $request->ip,
            'port' => $request->port,
            'status' => $request->status,
        ]);

        return Redirect::route('admin.servers.index')->with('success', 'Role updated.');
    }
}
