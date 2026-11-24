<?php

namespace App\Domain\Assets;

/**
 * Optional asset fields that count towards data completeness: the "Kelengkapan data" widget,
 * the asset list's `missing` filter, and the asset report's filter line.
 */
final class AssetDataQuality
{
    /** Column => label. */
    public const FIELDS = [
        'location_id' => 'Lokasi',
        'acquisition_date' => 'Tanggal perolehan',
        'acquisition_cost' => 'Nilai perolehan',
        'serial_number' => 'No seri',
        'image_path' => 'Foto aset',
    ];

    /** SQL condition: the column is filled in (a blank serial number counts as empty). */
    public static function filledCondition(string $column): string
    {
        if (! array_key_exists($column, self::FIELDS)) {
            throw new \InvalidArgumentException("Unknown quality field {$column}.");
        }

        return $column === 'serial_number' ? "nullif(trim(assets.{$column}), '') IS NOT NULL" : "assets.{$column} IS NOT NULL";
    }
}
