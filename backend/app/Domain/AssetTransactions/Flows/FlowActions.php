<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Notifications\TransactionFlowNotification;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Throwable;

/**
 * What an action step does (F-07 §4.1 Aksi sistem). Asset changes go through the core rules of
 * AssetTransactionService and are recorded under the run's number; the condition is the asset's
 * default "Kondisi Aset" field; a notification is an email sent once the step is committed.
 */
final class FlowActions
{
    public const KINDS = ['set_status', 'move_to_field_location', 'set_condition', 'loan_to_field_user', 'return', 'notify'];

    /**
     * The core action of a system type's flow (F-07f-2): records the built-in transaction with what
     * its form asked at the start. Every system flow has exactly one; no other flow may use it, so
     * it is not offered with KINDS.
     */
    public const CORE = 'core';

    public const MAX_MESSAGE = 500;

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetTransactionService $transactions,
        private readonly CategoryAttributeService $attributes,
        private readonly TransactionLog $log,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return int assets changed, or people notified
     */
    public function apply(TransactionRun $run, array $action): int
    {
        $values = $run->field_values;

        return match ($action['kind']) {
            self::CORE => $this->transactions->applyCoreForRun($run, (string) $run->definition()->first(['system_type', 'core_type'])?->coreType(),
                [...($run->system_values ?? []), 'field_values' => $values]),
            'set_status' => $this->transactions->applyForRun($run, TransactionType::STATUS_CHANGE, ['to_status' => $action['status']]),
            'move_to_field_location' => $this->transactions->applyForRun($run, TransactionType::TRANSFER,
                ['to_location_id' => $this->required($values, $action['field'], 'Lokasi tujuan belum diisi sehingga aset tidak dapat dipindahkan.')]),
            'set_condition' => $this->setCondition($run, isset($action['field'])
                ? $this->required($values, $action['field'], 'Kondisi belum diisi sehingga kondisi aset tidak dapat diubah.')
                : (string) $action['value']),
            'loan_to_field_user' => $this->transactions->applyForRun($run, TransactionType::LOAN, [
                'borrower_name' => $this->userName($this->required($values, $action['user_field'], 'Pemegang belum diisi sehingga aset tidak dapat dipinjamkan.')),
                'due_date' => isset($action['due_field']) && is_string($values[$action['due_field']] ?? null) ? $values[$action['due_field']] : null,
            ]),
            'return' => $this->transactions->applyForRun($run, TransactionType::RETURN, array_filter([
                'to_location_id' => isset($action['location_field']) && is_string($values[$action['location_field']] ?? null) ? $values[$action['location_field']] : null,
            ])),
            'notify' => $this->notify($run, $action),
            default => throw new LogicException("Unknown flow action {$action['kind']}."),
        };
    }

    /** Sets the default condition field of every asset of the run to the option given (value or label). */
    private function setCondition(TransactionRun $run, string $wanted): int
    {
        $assets = Asset::query()->whereIn('id', $run->assets()->select('assets.id'))->orderBy('id')->lockForUpdate()->get();
        $changed = 0;
        foreach ($assets as $asset) {
            $attribute = $this->attributes->conditionAttribute($asset->category_id)
                ?? throw ApiException::unprocessable('NO_CONDITION_FIELD', "Kategori aset {$asset->name} tidak memiliki field ".CategoryAttributeService::CONDITION_LABEL.'.');
            $option = collect($attribute->options ?? [])->first(fn (array $o) => strcasecmp($o['value'], $wanted) === 0 || strcasecmp($o['label'], $wanted) === 0)
                ?? throw ApiException::unprocessable('INVALID_CONDITION', "Kondisi \"{$wanted}\" tidak ada pada pilihan ".CategoryAttributeService::CONDITION_LABEL." aset {$asset->name}.");
            $values = $asset->attribute_values;
            $before = $values[$attribute->key] ?? null;
            if ($before === $option['value']) {
                continue;
            }
            $beforeLabel = collect($attribute->options)->firstWhere('value', $before)['label'] ?? ($before ?? '-');
            $values[$attribute->key] = $option['value'];
            $asset->forceFill(['attribute_values' => $values, 'lock_version' => $asset->lock_version + 1, 'updated_by' => $this->tenancy->tenant()->userId()])->save();
            $this->log->record($asset, TransactionType::NOTE, ['run_id' => $run->id], $run->transaction_date->toDateString(),
                CategoryAttributeService::CONDITION_LABEL.": {$beforeLabel} → {$option['label']}", $run->transaction_number);
            $this->audit->record('asset.updated', $asset, before: ['attributes' => [$attribute->key => $before]],
                after: ['attributes' => [$attribute->key => $option['value']]], metadata: ['transaction_number' => $run->transaction_number]);
            $changed++;
        }

        return $changed;
    }

    /**
     * Emails the recipients once the step is committed; a failing mail never undoes the step.
     *
     * @param  array<string, mixed>  $action
     */
    private function notify(TransactionRun $run, array $action): int
    {
        $users = $this->recipients($run, $action['to'] ?? []);
        if ($users === []) {
            return 0;
        }
        $notification = new TransactionFlowNotification(
            (string) $this->tenancy->tenant()->organization->name, $run->transaction_number,
            (string) $run->definition()->value('name'), (string) ($action['message'] ?? ''), $run->id,
        );
        DB::afterCommit(function () use ($users, $notification) {
            try {
                Notification::send($users, $notification);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return count($users);
    }

    /**
     * Active members to notify: the starter, a user, the user of a field, or the holders of a role.
     *
     * @param  array<string, mixed>  $to
     * @return list<User>
     */
    private function recipients(TransactionRun $run, array $to): array
    {
        $orgId = $this->tenancy->organizationId();
        $ids = match ($to['type'] ?? 'starter') {
            'user' => [(string) ($to['id'] ?? '')],
            'field' => is_string($run->field_values[$to['field'] ?? ''] ?? null) ? [$run->field_values[$to['field']]] : [],
            'role' => DB::table('user_roles')->where('organization_id', $orgId)->where('role_id', $to['id'] ?? null)->pluck('user_id')->all(),
            default => [$run->created_by],
        };
        $active = DB::table('organization_members')->where('organization_id', $orgId)->whereIn('user_id', $ids)
            ->where('status', User::STATUS_ACTIVE)->pluck('user_id');

        return User::query()->whereKey($active)->get()->all();
    }

    /** @param array<string, mixed> $values */
    private function required(array $values, string $field, string $message): string
    {
        $value = $values[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw ApiException::unprocessable('FIELD_MISSING', $message);
        }

        return $value;
    }

    private function userName(string $userId): string
    {
        return (string) (User::query()->whereKey($userId)->value('name')
            ?? throw ApiException::unprocessable('FIELD_MISSING', 'Pengguna pemegang tidak ditemukan.'));
    }
}
