<?php

namespace App\Domain\TenantMaster\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\GenericMaster\Models\GenericCondition;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterUsage;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\TenantMaster\Models\AssetCondition;
use Illuminate\Support\Facades\DB;

/**
 * Asset conditions of an organization. Standard conditions are copied from the Generic
 * Master (at provisioning, or later on request); the organization may rename or
 * deactivate them and add its own. Changing a copy never touches the generic record.
 */
final class ConditionService
{
    private const AUDIT = ['code', 'name', 'description', 'severity', 'sort_order', 'status'];

    public function __construct(
        private readonly MasterWriter $writer,
        private readonly AuditLogger $audit,
        private readonly MasterUsage $usage,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): AssetCondition
    {
        return $this->writer->create(new AssetCondition, $data, 'asset_condition.created', self::AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function update(AssetCondition $condition, array $data): AssetCondition
    {
        return $this->writer->update($condition, $data, 'asset_condition.updated', self::AUDIT);
    }

    public function archive(AssetCondition $condition, ?string $reason): AssetCondition
    {
        if ($this->usage->inUse(MasterUsage::CONDITION, $condition->id)) {
            throw ApiException::conflict('CONDITION_IN_USE', 'Kondisi masih dipakai oleh aset aktif.');
        }

        return $this->writer->archive($condition, 'asset_condition.archived', $reason);
    }

    public function restore(AssetCondition $condition): AssetCondition
    {
        return $this->writer->restore($condition, 'asset_condition.restored');
    }

    /**
     * Copies active generic conditions the organization does not have yet. Idempotent.
     * A tenant code already taken by a custom condition gets a "GEN_" prefix.
     *
     * @return list<AssetCondition> the conditions created
     */
    public function importGeneric(string $organizationId): array
    {
        return DB::transaction(function () use ($organizationId) {
            $existing = AssetCondition::query()->where('organization_id', $organizationId)->get(['code', 'generic_condition_id']);
            $linked = $existing->pluck('generic_condition_id')->filter()->all();
            $codes = $existing->pluck('code')->all();
            $created = [];

            $generics = GenericCondition::query()->where('status', MasterStatus::ACTIVE)->whereNotIn('id', $linked)->orderBy('sort_order')->get();
            foreach ($generics as $generic) {
                $condition = new AssetCondition;
                $condition->forceFill([
                    'organization_id' => $organizationId,
                    'generic_condition_id' => $generic->id,
                    'code' => in_array($generic->code, $codes, true) ? substr('GEN_'.$generic->code, 0, 30) : $generic->code,
                    'name' => $generic->name,
                    'description' => $generic->description,
                    'severity' => $generic->severity,
                    'sort_order' => $generic->sort_order,
                    'status' => MasterStatus::ACTIVE,
                ])->save();
                $created[] = $condition;
            }

            if ($created !== []) {
                $this->audit->record('asset_condition.imported_generic', 'AssetCondition', null,
                    after: ['codes' => array_map(fn (AssetCondition $c) => $c->code, $created)], organizationId: $organizationId);
            }

            return $created;
        });
    }
}
