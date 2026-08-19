<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExportJob extends Model
{
    use HasHashids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'label',
        'export_class',
        'export_args',
        'file_name',
        'file_path',
        'disk',
        'status',
        'error_message',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'export_args' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    public function hasSafeFilePath(): bool
    {
        return $this->disk === 'private'
            && is_string($this->file_path)
            && str_starts_with($this->file_path, 'exports/')
            && ! str_contains($this->file_path, '..')
            && ! str_contains($this->file_path, "\0");
    }

    public function isDownloadable(): bool
    {
        if (
            $this->status !== self::STATUS_COMPLETED
            || ! $this->hasSafeFilePath()
            || $this->expires_at === null
            || ! $this->expires_at->isFuture()
        ) {
            return false;
        }

        return Storage::disk($this->disk)->exists($this->file_path);
    }
}
