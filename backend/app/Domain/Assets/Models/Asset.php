<?php

namespace App\Domain\Assets\Models;

use App\Domain\Assets\AssetScope;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An asset of an organization.
 *
 * Internal identity ("generic data"): `id` (UUID, used by every reference) and `asset_number`
 * (system-generated). Neither ever changes (DB trigger) and assets are never deleted.
 * Everything else is user data. Location and status change only through
 * asset transactions, so the history always explains the current state.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $asset_number
 * @property string|null $serial_number
 * @property string $name
 * @property string $category_id
 * @property string|null $location_id
 * @property string $status
 * @property string|null $borrower_name
 * @property Carbon|null $loan_due_date
 * @property int $lock_version
 * @property Carbon|null $archived_at
 */
class Asset extends Model
{
    use BelongsToOrganization, HasUuids;

    /** Columns users edit directly (descriptive data). */
    public const EDITABLE = ['name', 'serial_number', 'category_id', 'acquisition_date', 'acquisition_cost', 'description'];

    protected $fillable = self::EDITABLE;

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'loan_due_date' => 'date',
            'lock_version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** Route binding also applies the user's data scope: out-of-scope assets are 404. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = $query instanceof Model ? $query->newQuery() : $query;
        if (app(Tenancy::class)->hasTenant()) {
            app(AssetScope::class)->apply($query);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantAssetCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AssetTransaction::class);
    }
}
