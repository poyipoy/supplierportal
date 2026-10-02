<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = ['theme', 'density', 'sidebar_state', 'page_size', 'quick_access', 'accent', 'dashboard_preferences', 'timezone', 'date_format', 'time_format', 'number_format'];

    protected function casts(): array
    {
        return ['page_size' => 'integer', 'quick_access' => 'array', 'revision' => 'integer', 'sidebar_revision' => 'integer', 'dashboard_preferences' => 'array', 'notification_preferences' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
