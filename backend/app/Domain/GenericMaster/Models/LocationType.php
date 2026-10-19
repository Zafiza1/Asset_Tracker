<?php

namespace App\Domain\GenericMaster\Models;

use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Kind of location. Built-in types (organization_id NULL) belong to the platform and are
 * read-only for tenants; an organization can add its own types next to them.
 *
 * In a tenant context reads see the built-in types plus the organization's own, and new
 * types are owned by that organization (RLS enforces the same in the database).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class LocationType extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'status'];

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $query): void {
            $tenancy = app(Tenancy::class);
            if ($tenancy->hasTenant()) {
                $query->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhere('organization_id', $tenancy->organizationId()));
            }
        });

        static::creating(function (self $type): void {
            $tenancy = app(Tenancy::class);
            if ($tenancy->hasTenant()) {
                $type->organization_id = $tenancy->organizationId();
            }
        });

        static::updating(function (self $type): void {
            if ($type->isDirty('organization_id')) {
                throw new LogicException('organization_id is immutable.');
            }
        });
    }

    public function isBuiltIn(): bool
    {
        return $this->organization_id === null;
    }

    /** @param Builder<self> $query */
    public function scopeBuiltIn(Builder $query): void
    {
        $query->whereNull('organization_id');
    }
}
