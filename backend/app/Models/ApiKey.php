<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project-scoped machine credential. See App\Services\ApiKeyService for
 * issuing and verifying keys.
 */
class ApiKey extends Model
{
    use TenantScoping;

    /** Scopes a key may carry — machine access is intentionally narrow. */
    public const SCOPES = ['event.ingest', 'integration.ingest'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'integration_id',
        'created_by',
        'name',
        'prefix',
        'key_hash',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * A key restricted to one integration may only act on that integration.
     */
    public function canUseIntegration(Integration $integration): bool
    {
        return $integration->project_id === $this->project_id
            && ($this->integration_id === null || $this->integration_id === $integration->id);
    }
}
