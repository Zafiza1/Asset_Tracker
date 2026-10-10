<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = ['branch_id', 'code', 'name', 'status'];

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
