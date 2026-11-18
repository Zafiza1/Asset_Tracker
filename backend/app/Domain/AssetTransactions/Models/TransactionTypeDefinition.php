<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
