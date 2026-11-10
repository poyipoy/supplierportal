<?php

namespace App\Models;

use App\Support\BusinessTime;
use App\Support\HashidCodec;
use App\Traits\HasHashids;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceRecord extends Model
{
    use HasHashids;
    use SoftDeletes;

    public const TYPE_GLOBAL = 'global';

    public const TYPE_MODULE = 'module';

    public const TYPES = [self::TYPE_GLOBAL, self::TYPE_MODULE];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    /** Display-only states derived from the stored status, the clock and soft deletion. */
    public const DISPLAY_OVERDUE = 'overdue';

    public const DISPLAY_DELETED = 'deleted';

    public const DISPLAY_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_ACTIVE,
        self::DISPLAY_OVERDUE,
        self::STATUS_COMPLETED,
        self::DISPLAY_DELETED,
    ];

    protected $fillable = [
        'type',
        'status',
        'title',
        'description',
        'starts_at',
        'estimated_end_at',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'estimated_end_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(MaintenanceRecordModule::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Records that block suppliers right now. A scheduled record blocks as soon as
     * its start passes, even before the scheduler has promoted it to active.
     */
    public function scopeEffective(Builder $query, ?DateTimeInterface $now = null): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_ACTIVE])
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', self::storageNow($now));
    }

    public function scopeUpcoming(Builder $query, ?DateTimeInterface $now = null): Builder
    {
        return $query
            ->where('status', self::STATUS_SCHEDULED)
            ->where('starts_at', '>', self::storageNow($now));
    }

    public function scopeOverdue(Builder $query, ?DateTimeInterface $now = null): Builder
    {
        return $query->effective($now)->where('estimated_end_at', '<', self::storageNow($now));
    }

    public function scopeRunningOnTime(Builder $query, ?DateTimeInterface $now = null): Builder
    {
        return $query->effective($now)->where('estimated_end_at', '>=', self::storageNow($now));
    }

    public function isGlobal(): bool
    {
        return $this->type === self::TYPE_GLOBAL;
    }

    public function isEffective(?DateTimeInterface $now = null): bool
    {
        if ($this->trashed() || ! in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_ACTIVE], true) || $this->starts_at === null) {
            return false;
        }

        return $this->starts_at->lessThanOrEqualTo(self::storageNow($now));
    }

    /** Scheduled and not yet due: still editable as a plan. */
    public function isUpcoming(?DateTimeInterface $now = null): bool
    {
        return ! $this->trashed()
            && $this->status === self::STATUS_SCHEDULED
            && $this->starts_at !== null
            && $this->starts_at->greaterThan(self::storageNow($now));
    }

    public function displayStatus(?DateTimeInterface $now = null): string
    {
        if ($this->trashed()) {
            return self::DISPLAY_DELETED;
        }

        if ($this->isEffective($now)) {
            return $this->estimated_end_at !== null && $this->estimated_end_at->lessThan(self::storageNow($now))
                ? self::DISPLAY_OVERDUE
                : self::STATUS_ACTIVE;
        }

        return $this->status;
    }

    /** Module keys of this record that still block (not individually deactivated). */
    public function activeModuleKeys(): array
    {
        return $this->modules
            ->whereNull('deactivated_at')
            ->pluck('module_key')
            ->values()
            ->all();
    }

    /** The detail page also opens soft-deleted records, still through the hashid. */
    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        if (($field !== null && $field !== $this->getRouteKeyName()) || ! is_string($value) || ctype_digit($value)) {
            return null;
        }

        $id = HashidCodec::decode($value);

        return $id === null ? null : $this->withTrashed()->where($this->getRouteKeyName(), $id)->first();
    }

    private static function storageNow(?DateTimeInterface $now): DateTimeInterface
    {
        return BusinessTime::toStorage($now ?? BusinessTime::now());
    }
}
