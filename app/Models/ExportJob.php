<?php

namespace App\Models;

use App\Services\UserPreferenceService;
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

    public const STATUS_CANCELLED = 'cancelled';

    public const STAGE_QUEUED = 'queued';

    public const STAGE_PREPARING = 'preparing';

    public const STAGE_GENERATING = 'generating';

    public const STAGE_FINALIZING = 'finalizing';

    public const STAGE_COMPLETED = 'completed';

    public const STAGE_FAILED = 'failed';

    public const STAGE_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'label',
        'export_class',
        'export_args',
        'format',
        'export_options',
        'file_name',
        'file_path',
        'disk',
        'status',
        'progress_stage',
        'progress',
        'total_rows',
        'processed_rows',
        'processed_chunks',
        'error_message',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'export_args' => 'array',
            'export_options' => 'array',
            'progress' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'processed_chunks' => 'array',
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

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function progressMessage(?string $locale = null): string
    {
        $locale = UserPreferenceService::normalizeLocale($locale ?? app()->getLocale());

        return match ($this->progress_stage) {
            self::STAGE_QUEUED => __('exports.progress.queued', [], $locale),
            self::STAGE_PREPARING => __('exports.progress.preparing', [], $locale),
            self::STAGE_GENERATING => $this->total_rows > 0
                ? __('exports.progress.rows', ['processed' => number_format($this->processed_rows), 'total' => number_format($this->total_rows)], $locale)
                : __('exports.progress.generating', [], $locale),
            self::STAGE_FINALIZING => $this->total_rows > 0
                ? __('exports.progress.finalizing_rows', ['total' => number_format($this->total_rows)], $locale)
                : __('exports.progress.finalizing', [], $locale),
            self::STAGE_COMPLETED => __('exports.progress.completed', [], $locale),
            self::STAGE_FAILED => __('exports.progress.failed', [], $locale),
            self::STAGE_CANCELLED => __('exports.progress.cancelled', [], $locale),
            default => __('exports.progress.processing', [], $locale),
        };
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
