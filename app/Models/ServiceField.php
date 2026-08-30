<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceField extends Model
{
    use HasFactory;

    protected $fillable = ['service_id', 'label', 'field_key', 'type', 'options', 'required'];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
