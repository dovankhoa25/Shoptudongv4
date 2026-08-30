<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeOption extends Model
{
    protected $fillable = ['attribute_id', 'option_value', 'status'];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }
}
