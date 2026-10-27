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

    /** All settings are system defaults (column defaults), not editable by organizations. */
    public const EDITABLE = [];

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
