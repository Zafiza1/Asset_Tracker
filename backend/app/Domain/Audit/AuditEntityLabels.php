<?php

namespace App\Domain\Audit;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Role;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\Models\TenantAttributeDefinition;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Http\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns an audit row's (entity_type, entity_id) into what a person recognises: the object's
 * code and name, read from the record as it is now (or, if it is gone, from the snapshot
 * stored in the log). Reads go through the normal models, so tenant scopes and RLS apply.
 */
final class AuditEntityLabels
{
    /** entity_type (class basename) => [model, code column|null, name column|null] */
    private const TYPES = [
        'Asset' => [Asset::class, null, 'name'],
        'AssetTransaction' => [AssetTransaction::class, 'transaction_number', null],
        'ApprovalRequest' => [ApprovalRequest::class, null, 'summary'],
        'User' => [User::class, 'email', 'name'],
        'Department' => [Department::class, 'code', 'name'],
        'Location' => [Location::class, 'code', 'name'],
        'Role' => [Role::class, 'code', 'name'],
        'Organization' => [Organization::class, 'code', 'name'],
        'OrganizationInvitation' => [OrganizationInvitation::class, null, 'email'],
        'LicenseCode' => [LicenseCode::class, 'code', null],
        'TenantAssetCategory' => [TenantAssetCategory::class, 'code', 'name'],
        'TenantAssetDefinition' => [TenantAssetDefinition::class, 'code', 'name'],
        'TenantCategoryAttribute' => [TenantCategoryAttribute::class, 'key', 'label'],
        'TenantAttributeDefinition' => [TenantAttributeDefinition::class, 'key', 'label'],
        'GenericAssetCategory' => [GenericAssetCategory::class, 'code', 'name'],
        'GenericAssetDefinition' => [GenericAssetDefinition::class, 'code', 'name'],
        'GenericAttributeDefinition' => [GenericAttributeDefinition::class, 'key', 'label'],
    ];

    /** Tenant objects searched by their current code/name (users are searched as actors). */
    private const SEARCHABLE = ['Asset', 'AssetTransaction', 'Department', 'Location', 'TenantAssetCategory', 'LicenseCode'];

    private const SNAPSHOT_CODE = ['code', 'transaction_number', 'key', 'email'];

    private const SNAPSHOT_NAME = ['name', 'label', 'summary', 'title'];

    /**
     * Sets `entity_label` ({code, name} or null) on each log.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public static function attach(iterable $logs): void
    {
        $logs = collect($logs);
        $found = [];
        foreach ($logs->whereNotNull('entity_id')->groupBy('entity_type') as $type => $rows) {
            $class = self::TYPES[$type][0] ?? null;
            if ($class === null) {
                continue;
            }
            $ids = $rows->pluck('entity_id')->filter(fn ($id) => self::isUuid($id))->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $found[$type] = self::query($class)->whereIn('id', $ids)->get()->keyBy(fn (Model $m) => (string) $m->getKey());
        }

        foreach ($logs as $log) {
            [, $code, $name] = self::TYPES[$log->entity_type] ?? [null, null, null];
            $model = $found[$log->entity_type][$log->entity_id] ?? null;
            $label = $model
                ? ['code' => $code ? $model->getAttribute($code) : null, 'name' => $name ? $model->getAttribute($name) : null]
                : self::fromSnapshot($log);
            $log->setAttribute('entity_label', $label && ($label['code'] || $label['name']) ? $label : null);
        }
    }

    /** Logs whose object's code or name matches the term (for search). */
    public static function whereMatches(Builder $query, string $term): Builder
    {
        $like = ListQuery::like($term);

        // Empty when nothing matches; Laravel then drops the nested group.
        return $query->where(function ($q) use ($like) {
            foreach (self::SEARCHABLE as $type) {
                [$class, $code, $name] = self::TYPES[$type];
                $ids = self::query($class)
                    ->where(function ($m) use ($code, $name, $like) {
                        foreach (array_filter([$code, $name]) as $column) {
                            $m->orWhere($column, 'ilike', $like);
                        }
                    })
                    ->limit(500)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id);
                if ($ids->isNotEmpty()) {
                    $q->orWhere(fn ($e) => $e->where('entity_type', $type)->whereIn('entity_id', $ids));
                }
            }
        });
    }

    /** @param  class-string<Model>  $class */
    private static function query(string $class): Builder
    {
        return $class::query();
    }

    /** @return array{code: mixed, name: mixed}|null */
    private static function fromSnapshot(AuditLog $log): ?array
    {
        $snapshot = array_merge((array) $log->metadata, (array) $log->before, (array) $log->after);
        $pick = fn (array $keys) => collect($keys)->map(fn ($k) => $snapshot[$k] ?? null)->first(fn ($v) => is_scalar($v) && $v !== '');

        return ['code' => $pick(self::SNAPSHOT_CODE), 'name' => $pick(self::SNAPSHOT_NAME)];
    }

    private static function isUuid(?string $value): bool
    {
        return $value !== null && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
