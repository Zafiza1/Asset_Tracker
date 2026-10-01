<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Asset extends Model
{
    use HasFactory, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return AssetFactory::new();
    }

    /**
     * system_id is intentionally absent here: it is platform-generated and
     * immutable (see booted()), never settable from a request.
     */
    protected $fillable = [
        'organization_id',
        'project_id',
        'serial_number',
        'name',
        'description',
        'asset_type',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset) {
            if (empty($asset->system_id)) {
                $asset->system_id = 'AST-' . strtoupper((string) Str::ulid());
            }
        });
    }

    /**
     * Assets are addressed via the public, immutable system_id rather than
     * the internal auto-increment id (Section 6/24: two identities).
     */
    public function getRouteKeyName(): string
    {
        return 'system_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Current-location pointer (see App\Models\AssetLocation). Nullable —
     * an asset has no location until its first movement is recorded.
     */
    public function locationAssignment(): HasOne
    {
        return $this->hasOne(AssetLocation::class);
    }

    /**
     * Full movement history, newest first (Section 26).
     */
    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class)->orderByDesc('occurred_at');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(EventLog::class)->orderByDesc('occurred_at');
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOfType($query, string $assetType)
    {
        return $query->where('asset_type', $assetType);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
