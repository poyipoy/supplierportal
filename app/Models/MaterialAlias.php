<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAlias extends Model
{
    protected $fillable = [
        'material_master_id',
        'alias',
        'normalized_alias',
        'source_note',
    ];

    public function materialMaster(): BelongsTo
    {
        return $this->belongsTo(MaterialMaster::class);
    }
}
