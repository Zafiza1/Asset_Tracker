<?php

namespace App\Models;

use App\Events\AssetCreated;
use App\Events\AssetDeleted;
use App\Events\AssetStatusChanged;
use App\Events\AssetUpdated;
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
        'last_seen_at' => 'datetime',
    ];

    /**
     * Mirrors the column default so a freshly created asset (and its
     * asset.created event) reports its status without a reload.
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset) {
            if (empty($asset->system_id)) {
                $asset->system_id = 'AST-' . strtoupper((string) Str::ulid());
            }
        });

        // Standard events (docs/architecture/events.md) fire from the model so
        // every write path — API, integrations, modules — emits them.
        static::created(fn (self $asset) => AssetCreated::dispatch($asset));

        static::updated(function (self $asset) {
            AssetUpdated::dispatch($asset);

            if ($asset->wasChanged('status')) {
                AssetStatusChanged::dispatch($asset, (string) $asset->getOriginal('status'), (string) $asset->status);
            }
        });

        static::deleted(fn (self $asset) => AssetDeleted::dispatch($asset));
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

    /**
     * Device binding history; active bindings have a null unbound_at.
     */
    public function deviceBindings(): HasMany
    {
        return $this->hasMany(DeviceBinding::class)->orderByDesc('bound_at');
    }

    /**
     * Record integration activity without emitting asset.updated — a
     * detection is reported through its own event (asset.detected etc.).
     */
    public function touchLastSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
