<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Append-only Material Master / HS Code Rule / Reference Price Rule audit (Q3).
 * Rows are inserted, never updated or deleted through the application.
 */
class MasterDataAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ENTITY_MATERIAL = 'material_master';

    public const ENTITY_HS_RULE = 'hs_code_rule';

    public const ENTITY_REFERENCE_PRICE_RULE = 'reference_price_rule';

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const STATUS_CHANGED = 'status_changed';

    public const DELETED = 'deleted';

    public const DELETE_REFUSED = 'delete_refused';

    protected $fillable = ['entity_type', 'entity_id', 'action', 'before', 'after', 'actor_id', 'actor_role'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Master data audit rows are append-only.'));
        static::deleting(fn () => throw new LogicException('Master data audit rows are append-only.'));
    }

    public static function record(Model $entity, string $action, ?array $before, ?array $after, ?User $actor): self
    {
        $entityType = match (true) {
            $entity instanceof HsCodeRule => self::ENTITY_HS_RULE,
            $entity instanceof MaterialMaster => self::ENTITY_MATERIAL,
            $entity instanceof ReferencePriceRule => self::ENTITY_REFERENCE_PRICE_RULE,
            default => throw new InvalidArgumentException('Unsupported master data audit entity: '.$entity::class),
        };

        return self::create([
            'entity_type' => $entityType,
            'entity_id' => $entity->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
