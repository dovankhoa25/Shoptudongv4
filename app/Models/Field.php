<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Field extends Model
{
    protected $fillable = [
        'label',
        'field_key',
        'type',
        'options',
        'required',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    // 1 Field thuộc nhiều Service (pivot)
    public function services()
    {
        return $this->belongsToMany(Service::class, 'field_service');
    }

    // 1 Field có nhiều Value khi User đặt hàng
    public function orderFieldValues()
    {
        return $this->hasMany(ServiceOrderFieldValue::class);
    }
}
