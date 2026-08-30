<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServerGameLogin extends Model
{
    use HasFactory;

    protected $table = 'server_game_login';

    protected $fillable = [
        'ip',
        'port',
        'name',
    ];
}
