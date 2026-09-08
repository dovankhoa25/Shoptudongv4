<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NroAccountSnapshot extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['data_json' => 'array', 'summary_json' => 'array', 'completeness_json' => 'array', 'captured_at' => 'datetime']; }
}
