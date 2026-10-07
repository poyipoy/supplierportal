<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class ExportPreset extends Model
{
    use HasHashids;

    protected $fillable = ['user_id', 'export_key', 'name', 'columns', 'filters', 'format', 'is_default'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'filters' => 'array', 'is_default' => 'boolean'];
    }
}
