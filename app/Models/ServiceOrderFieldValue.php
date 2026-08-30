<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOrderFieldValue extends Model
{
    protected $fillable = [
        'service_order_id',
        'field_id',
        'value',
    ];

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function field()
    {
        return $this->belongsTo(Field::class);
    }
}
