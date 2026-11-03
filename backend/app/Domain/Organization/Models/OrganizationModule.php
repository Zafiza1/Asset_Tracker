<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An optional module the organization switched on; a module without a row is off.
 * Queries run in the tenant context, so they only see the active organization's modules.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $module
 */
class OrganizationModule extends Model
{
    use BelongsToOrganization, HasUuids;

    public const BARCODE = 'barcode';

    /** Modules organizations can switch on and off: key => [name, description]. */
    public const CATALOG = [
        self::BARCODE => [
            'name' => 'Barcode',
            'description' => 'Barcode 1D (Code 128) dan 2D (QR Code) berisi nomor aset untuk dicetak sebagai label dan dipindai aplikasi mobile.',
        ],
    ];

    protected $fillable = ['module'];

    /** @return list<string> keys of the active organization's switched-on modules */
    public static function enabledKeys(): array
    {
        return self::query()->whereIn('module', array_keys(self::CATALOG))->orderBy('module')->pluck('module')->all();
    }

    public static function isEnabled(string $module): bool
    {
        return self::query()->where('module', $module)->exists();
    }
}
