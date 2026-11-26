<?php

namespace App\Domain\Assets\Models;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string|null $custodian_user_id member who answers for the asset (F-09)
 * @property string|null $department_id department that owns/uses the asset (F-09)
 * @property array<string, mixed> $attribute_values custom field values (category attributes), keyed by attribute key
 * @property string $status
 * @property string|null $image_path photo on AssetService::IMAGE_DISK
 * @property string|null $document_path document on AssetService::IMAGE_DISK
 * @property string|null $document_name its original file name
 * @property string|null $borrower_name
 * @property Carbon|null $loan_due_date
 * @property int $lock_version
 * @property Carbon|null $archived_at
 */
class Asset extends Model
{
    use BelongsToOrganization, HasUuids;

    /** Columns users edit directly (descriptive data). */
    public const EDITABLE = ['name', 'serial_number', 'category_id', 'acquisition_date', 'acquisition_cost', 'description',
        'custodian_user_id', 'department_id'];

    protected $fillable = self::EDITABLE;

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'loan_due_date' => 'date',
            'lock_version' => 'integer',
            'archived_at' => 'datetime',
            // Keys of optional category fields that do not apply to this asset.
            'hidden_attributes' => 'array',
            // Fields only this asset has: [{label, data_type, value}].
            'extra_attributes' => 'array',
        ];
    }

    /** Stored as a JSON object (also when empty), read as an associative array. */
    protected function attributeValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? [] : json_decode($value, true),
            set: fn (array $value) => json_encode((object) $value),
        );
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

    /** The status as shown for this asset; a cylinder has its own labels. */
    public function statusLabel(): string
    {
        return AssetStatus::label($this->status, $this->loadMissing('category')->category?->code === StandardCategories::CYLINDER);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantAssetCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** The custodian's membership in this organization (status, job title), with `user` for the name. */
    public function custodianMember(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'custodian_user_id', 'user_id');
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
