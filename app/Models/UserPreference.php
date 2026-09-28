<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = ['theme', 'density', 'sidebar_state', 'page_size', 'quick_access'];

    protected function casts(): array
    {
        return ['page_size' => 'integer', 'quick_access' => 'array', 'revision' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
