<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $organization_id
 * @property string $asset_number_format
 * @property string $transaction_number_format
 * @property bool $allow_self_approval_default
 * @property int $max_upload_mb
 */
class OrganizationSetting extends Model
{
    use BelongsToOrganization;

    public const EDITABLE = ['asset_number_format', 'transaction_number_format', 'allow_self_approval_default', 'max_upload_mb'];

    protected $primaryKey = 'organization_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = self::EDITABLE;

    protected function casts(): array
    {
        return [
            'allow_self_approval_default' => 'boolean',
            'max_upload_mb' => 'integer',
        ];
    }
}
