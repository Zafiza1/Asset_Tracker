<?php

namespace App\Domain\Shared\Masters;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create/update/status changes of simple master records, each in one database
 * transaction with its audit entry. Callers pass already validated, whitelisted
 * attributes; organization_id is never among them (tenant models take it from context).
 */
final class MasterWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $auditFields
     * @return T
     */
    public function create(Model $model, array $attributes, string $action, array $auditFields): Model
    {
        return DB::transaction(function () use ($model, $attributes, $action, $auditFields) {
            $model->forceFill($attributes)->save();
            // Load column defaults (status, flags, ...) so the response and the audit entry are complete.
            $model->refresh();
            // Outside a tenant context (system backfills) the entry still belongs to the row's organization.
            $this->audit->record($action, $model, after: $model->only($auditFields), organizationId: $model->getAttribute('organization_id'));

            return $model;
        });
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $auditFields
     * @return T
     */
    public function update(Model $model, array $attributes, string $action, array $auditFields, ?callable $afterSave = null): Model
    {
        return DB::transaction(function () use ($model, $attributes, $action, $auditFields, $afterSave) {
            $before = $model->only($auditFields);
            $model->forceFill($attributes)->save();
            if ($afterSave !== null) {
                $afterSave($model);
            }
            [$b, $a] = AuditLogger::diff($before, $model->only($auditFields));
            if ($a !== []) {
                $this->audit->record($action, $model, before: $b, after: $a);
            }

            return $model;
        });
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public function archive(Model $model, string $action, ?string $reason): Model
    {
        if ($model->getAttribute('status') === MasterStatus::ARCHIVED) {
            throw ApiException::conflict('ALREADY_ARCHIVED', 'Data sudah diarsipkan.');
        }

        return $this->setStatus($model, MasterStatus::ARCHIVED, $action, $reason);
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public function restore(Model $model, string $action): Model
    {
        if ($model->getAttribute('status') !== MasterStatus::ARCHIVED) {
            throw ApiException::conflict('NOT_ARCHIVED', 'Data tidak dalam status arsip.');
        }

        return $this->setStatus($model, MasterStatus::ACTIVE, $action, null);
    }

    private function setStatus(Model $model, string $status, string $action, ?string $reason): Model
    {
        return DB::transaction(function () use ($model, $status, $action, $reason) {
            $before = $model->getAttribute('status');
            $model->forceFill(['status' => $status])->save();
            $this->audit->record($action, $model, before: ['status' => $before], after: ['status' => $status],
                metadata: $reason ? ['reason' => $reason] : []);

            return $model;
        });
    }
}
