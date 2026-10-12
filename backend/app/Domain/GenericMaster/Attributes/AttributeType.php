<?php

namespace App\Domain\GenericMaster\Attributes;

/**
 * Dynamic attribute data types supported in V1.0 (docs/03-database-erd.md §6) and the
 * declarative validation keys each type accepts. Shared by Generic Master attributes,
 * tenant attributes, tenant overrides, and (from Phase 4) asset attribute values.
 */
final class AttributeType
{
    public const STRING = 'string';

    public const TEXT = 'text';

    public const INTEGER = 'integer';

    public const DECIMAL = 'decimal';

    public const BOOLEAN = 'boolean';

    public const DATE = 'date';

    public const ENUM = 'enum';

    public const MULTI_ENUM = 'multi_enum';

    public const ALL = [self::STRING, self::TEXT, self::INTEGER, self::DECIMAL, self::BOOLEAN, self::DATE, self::ENUM, self::MULTI_ENUM];

    /** @var array<string, list<string>> */
    public const VALIDATION_KEYS = [
        self::STRING => ['min_length', 'max_length', 'pattern'],
        self::TEXT => ['min_length', 'max_length'],
        self::INTEGER => ['min', 'max'],
        self::DECIMAL => ['min', 'max', 'decimals'],
        self::DATE => ['min', 'max'],
        self::BOOLEAN => [],
        self::ENUM => [],
        self::MULTI_ENUM => [],
    ];

    /** Upper bound on length when a definition does not state one. */
    public const MAX_LENGTH = [self::STRING => 255, self::TEXT => 5000];

    public const MAX_DECIMALS = 6;

    public const MAX_OPTIONS = 200;

    /** Keys of the core asset columns; dynamic attributes may not shadow them. */
    public const RESERVED_KEYS = [
        'id', 'asset_number', 'serial_number', 'name', 'description', 'status', 'condition', 'condition_id',
        'location', 'location_id', 'branch', 'branch_id', 'department', 'department_id', 'custodian',
        'custodian_user_id', 'acquisition_date', 'acquisition_cost', 'currency', 'category', 'definition',
    ];

    public static function hasOptions(string $type): bool
    {
        return $type === self::ENUM || $type === self::MULTI_ENUM;
    }

    public static function isNumeric(string $type): bool
    {
        return $type === self::INTEGER || $type === self::DECIMAL;
    }
}
