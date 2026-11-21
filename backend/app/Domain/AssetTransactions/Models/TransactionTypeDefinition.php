<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A transaction type defined by the organization (docs/factory/features/F-07): its code (the
 * number prefix), the categories it applies to and its form fields. Its transactions are stored
 * in asset_transactions with type 'custom'.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property bool $multi_asset
 * @property bool $locks_assets
 * @property string|null $closes_type_id
 * @property string $status
 * @property string|null $created_by
 * @property string|null $system_type one of the built-in types (F-07f); NULL for the organization's own types
 * @property string|null $core_type the built-in type an organization type copied from a system type records (F-08)
 */
class TransactionTypeDefinition extends Model
{
    use BelongsToOrganization, HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::INACTIVE];

    protected $fillable = ['code', 'name', 'description', 'icon', 'multi_asset', 'locks_assets', 'closes_type_id', 'status'];

    protected function casts(): array
    {
        return [
            'multi_asset' => 'boolean',
            'locks_assets' => 'boolean',
        ];
    }

    /** One of the seven built-in types kept as a row of the organization (F-07f, D-26). */
    public function isSystem(): bool
    {
        return $this->system_type !== null;
    }

    /**
     * The built-in transaction this type's core action records: its own kind for a system type,
     * the kind it was copied from for an organization type made from one (F-08); null otherwise.
     */
    public function coreType(): ?string
    {
        return $this->system_type ?? $this->core_type;
    }

    /** The current organization's row of a built-in type (installed for every organization). */
    public static function system(string $type): ?self
    {
        return self::query()->where('system_type', $type)->first();
    }

    /** The organization's own types: what "Jenis organisasi" means everywhere except the type list. */
    public function scopeOrganizationOwn(Builder $query): Builder
    {
        return $query->whereNull('system_type');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(TransactionTypeField::class, 'type_definition_id')->orderBy('sort_order')->orderBy('key');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TenantAssetCategory::class, 'transaction_type_categories', 'type_definition_id', 'category_id')
            ->withPivot('organization_id');
    }

    public function closesType(): BelongsTo
    {
        return $this->belongsTo(self::class, 'closes_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AssetTransaction::class, 'type_definition_id');
    }

    /** The published flow its new transactions follow (F-07b); none: they are recorded at once. */
    public function activeFlow(): BelongsTo
    {
        return $this->belongsTo(TransactionFlowVersion::class, 'active_flow_version_id');
    }

    /** The flow version being drawn, if any (one per type). */
    public function draftFlow(): HasOne
    {
        return $this->hasOne(TransactionFlowVersion::class, 'type_definition_id')->where('status', TransactionFlowVersion::DRAFT);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
