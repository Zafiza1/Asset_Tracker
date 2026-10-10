<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = ['code', 'name', 'address', 'phone', 'status'];

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
