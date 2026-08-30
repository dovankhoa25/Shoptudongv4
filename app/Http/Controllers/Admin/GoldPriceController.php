<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoldPrices\GoldPriceResource;
use App\Models\GoldPrice;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class GoldPriceController extends Controller
{
    public function index(Request $request)
    {
        $query = GoldPrice::with('server');

        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $prices = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Admin/GoldPrices/Index', [
            'prices'  => GoldPriceResource::collection($prices),
            'servers' => Server::all(['id', 'name']),
            'filters' => $request->only(['server_id', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'server_id'                => 'required|exists:servers,id',
            'price'          => 'required|numeric|min:0',
            'import_price'   => 'required|numeric|min:0',
            'status'                   => 'required|boolean',
        ]);

        GoldPrice::create([
            'server_id'               => $request->server_id,
            'price'         => $request->price,
            'import_price'  => $request->import_price,
            'status'                  => $request->status,
        ]);

        return Redirect::route('admin.gold-prices.index')->with('success', 'Giá vàng đã được tạo.');
    }

    public function update(Request $request, GoldPrice $goldprice)
    {
        $request->validate([
            'server_id'                => 'required|exists:servers,id',
            'price'          => 'required|numeric|min:0',
            'import_price'   => 'required|numeric|min:0',
            'status'                   => 'required|boolean',
        ]);

        $goldprice->update([
            'server_id'               => $request->server_id,
            'price'         => $request->price,
            'import_price'  => $request->import_price,
            'status'                  => $request->status,
        ]);

        return Redirect::route('admin.gold-prices.index')->with('success', 'Giá vàng đã được cập nhật.');
    }

    public function destroy(GoldPrice $goldPrice)
    {
        $goldPrice->delete();

        return Redirect::route('admin.gold-prices.index')->with('success', 'Giá vàng đã được xoá.');
    }
}
