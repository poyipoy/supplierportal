<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierIdentityProvider extends Model
{
    use HasHashids;

    public const PROTOCOL_SAML = 'saml';

    public const PROTOCOL_OIDC = 'oidc';

    public const PROTOCOLS = [self::PROTOCOL_SAML, self::PROTOCOL_OIDC];

    protected $fillable = [
        'supplier_id',
        'name',
        'protocol',
        'domain',
        'is_active',
        'enforce_sso',
        'config',
        'created_by',
    ];

    /**
     * Config holds client secrets / certificates. Never serialize it to a
     * response body (the admin edit form re-populates masked fields, not
     * the raw stored value).
     */
    protected $hidden = [
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'enforce_sso' => 'boolean',
            'config' => 'encrypted:array',
            'last_used_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Resolve the active SSO connection (if any) that should handle a login
     * attempt for the given email address, based on its domain.
     */
    public static function findActiveForEmail(string $email): ?self
    {
        $email = Str::lower(trim($email));
        $domain = Str::lower(Str::after($email, '@'));

        if ($domain === '' || $domain === $email) {
            return null;
        }

        return static::query()->active()->where('domain', $domain)->first();
    }

    public function isSaml(): bool
    {
        return $this->protocol === self::PROTOCOL_SAML;
    }

    public function isOidc(): bool
    {
        return $this->protocol === self::PROTOCOL_OIDC;
    }
}
