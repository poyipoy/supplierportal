<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class ExportPreset extends Model
{
    use HasHashids;

    public const SCOPE_PERSONAL = 'personal';

    public const SCOPE_TEAM = 'team';

    protected $fillable = ['user_id', 'export_key', 'scope', 'name', 'columns', 'filters', 'format', 'csv_delimiter', 'is_default'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'filters' => 'array', 'is_default' => 'boolean'];
    }
}
