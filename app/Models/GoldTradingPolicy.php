<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoldTradingPolicy extends Model
{
    protected $fillable = ['server_id', 'asset_type', 'sell_enabled', 'import_enabled', 'import_stock_limit_bars'];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'sell_enabled' => 'boolean',
            'import_enabled' => 'boolean',
            'import_stock_limit_bars' => 'integer',
        ];
    }
}
