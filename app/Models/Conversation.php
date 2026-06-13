<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Conversation extends Model
{
    use HasFactory, HasHashids;

    public const STATUS_OPEN = 'open';
    public const STATUS_WAITING_SUPPLIER = 'waiting_supplier';
    public const STATUS_WAITING_PURCHASING = 'waiting_purchasing';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'conversable_type',
        'conversable_id',
        'purchasing_user_id',
        'supplier_user_id',
        'status',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    /**
     * Polymorphic: bisa PR atau PO.
     */
    public function conversable(): MorphTo
    {
        return $this->morphTo();
    }

    public function purchasingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchasing_user_id');
    }

    public function supplierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    // ─── Scopes ───

    /**
     * Filter conversation yang bisa diakses oleh user tertentu.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('purchasing_user_id', $userId)
              ->orWhere('supplier_user_id', $userId);
        });
    }

    // ─── Helpers ───

    /**
     * Cek apakah user adalah member dari conversation ini.
     */
    public function isMember($userId): bool
    {
        return $this->purchasing_user_id == $userId || $this->supplier_user_id == $userId;
    }

    /**
     * Dapatkan partner (lawan bicara) dari sudut pandang user tertentu.
     */
    public function getPartner($userId): ?User
    {
        if ($this->purchasing_user_id == $userId) {
            return $this->supplierUser;
        }
        return $this->purchasingUser;
    }

    public function markWaitingForPartner(User $sender): void
    {
        if ($sender->id === $this->purchasing_user_id) {
            $this->forceFill([
                'status' => self::STATUS_WAITING_SUPPLIER,
                'resolved_at' => null,
            ])->save();

            return;
        }

        if ($sender->id === $this->supplier_user_id) {
            $this->forceFill([
                'status' => self::STATUS_WAITING_PURCHASING,
                'resolved_at' => null,
            ])->save();
        }
    }

    public function markResolved(): void
    {
        $this->forceFill([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();
    }

    public function statusLabelFor(User $viewer): string
    {
        return match ($this->status) {
            self::STATUS_WAITING_SUPPLIER => $viewer->id === $this->supplier_user_id
                ? 'Needs Reply'
                : 'Waiting for Supplier',
            self::STATUS_WAITING_PURCHASING => $viewer->id === $this->purchasing_user_id
                ? 'Needs Reply'
                : 'Waiting for Purchasing',
            self::STATUS_RESOLVED => 'Completed',
            default => 'Active',
        };
    }

    public function statusBadgeClassFor(User $viewer): string
    {
        if ($this->status === self::STATUS_RESOLVED) {
            return 'bg-success';
        }

        if (
            ($this->status === self::STATUS_WAITING_SUPPLIER && $viewer->id === $this->supplier_user_id)
            || ($this->status === self::STATUS_WAITING_PURCHASING && $viewer->id === $this->purchasing_user_id)
        ) {
            return 'bg-warning text-dark';
        }

        return $this->status === self::STATUS_OPEN ? 'bg-secondary' : 'bg-primary';
    }

    /**
     * Hitung pesan yang belum dibaca oleh user tertentu.
     */
    public function unreadCountFor($userId): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Pesan terakhir dalam conversation.
     */
    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Label konteks (Nomor PR/PO).
     */
    public function getContextLabelAttribute(): string
    {
        if ($this->conversable_type === PurchaseRequisition::class) {
            return 'PR: ' . ($this->conversable->pr_number ?? '#' . $this->conversable_id);
        }
        if ($this->conversable_type === PurchaseOrder::class) {
            return 'PO: ' . ($this->conversable->po_number ?? '#' . $this->conversable_id);
        }
        return '#' . $this->conversable_id;
    }
}
