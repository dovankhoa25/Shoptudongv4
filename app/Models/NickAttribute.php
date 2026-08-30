<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class NickAttribute extends Pivot
{
    protected $table = 'nick_attributes';

    protected $fillable = ['nick_id', 'attribute_id', 'attribute_option_id'];

    public $timestamps = true;
}
