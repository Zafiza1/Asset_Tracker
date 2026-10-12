<?php

namespace App\Domain\AssetTransactions\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\AssetCondition;
use Illuminate\Support\Facades\DB;

/**
 * Manual asset transactions. A transaction is recorded and applied to the asset in one
 * database transaction while the asset row is locked, so concurrent transactions on the
 * same asset are serialized and the history always matches the asset's current state.
 *
 * Rules (kept deliberately small):
 *  - archived assets accept no transactions;
 *  - an asset on loan must be returned before it is moved or its status changes;
 *  - disposed assets cannot be moved or lent (a status change can still correct the status).
 */
final class AssetTransactionService
{
    /** Extra permission per type, on top of transaction.create (checked by the route). */
    private const PERMISSIONS = [
        TransactionType::TRANSFER => 'asset.transfer',
        TransactionType::STATUS_CHANGE => 'asset.status_change',
        TransactionType::LOAN => 'asset.assign',
        TransactionType::RETURN => 'asset.assign',
    ];

    public function __construct(
        private readonly AssetScope $scope,
        private readonly TransactionLog $log,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function create(array $data): AssetTransaction
    {
        $type = $data['type'];
        $this->authorize($type, $data);

        return DB::transaction(function () use ($type, $data) {
            $asset = $this->lockAsset($data['asset_id']);
            if ($asset->isArchived()) {
                throw ApiException::conflict('ASSET_ARCHIVED', 'Aset nonaktif tidak dapat ditransaksikan. Aktifkan kembali terlebih dahulu.');
            }

            [$assetChanges, $logChanges] = match ($type) {
                TransactionType::TRANSFER => $this->transfer($asset, $data),
                TransactionType::STATUS_CHANGE => $this->statusChange($asset, $data),
                TransactionType::LOAN => $this->loan($asset, $data),
                TransactionType::RETURN => $this->return($asset, $data),
                TransactionType::NOTE => [[], []],
            };

            if ($assetChanges !== []) {
                $asset->forceFill([...$assetChanges, 'lock_version' => $asset->lock_version + 1, 'updated_by' => $this->tenancy->tenant()->userId()])->save();
            }
            $transaction = $this->log->record($asset, $type, $logChanges, $data['transaction_date'] ?? null, $data['notes'] ?? null);
            $this->audit->record('asset_transaction.created', $transaction, after: [
                'transaction_number' => $transaction->transaction_number,
                'asset_number' => $asset->asset_number,
                'type' => $type,
                ...$logChanges,
            ]);

            return $transaction;
        });
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function transfer(Asset $asset, array $data): array
    {
        $this->assertStatusNotIn($asset, [AssetStatus::ON_LOAN, AssetStatus::DISPOSED], 'dipindahkan');
        $to = $data['to_location_id'] ?? null;
        if ($to === null) {
            throw $this->fieldError('to_location_id', 'Lokasi tujuan wajib dipilih.');
        }
        if ($to === $asset->location_id) {
            throw $this->fieldError('to_location_id', 'Lokasi tujuan sama dengan lokasi saat ini.');
        }
        $this->assertTargetLocation($to);

        return [['location_id' => $to], ['from_location_id' => $asset->location_id, 'to_location_id' => $to]];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function statusChange(Asset $asset, array $data): array
    {
        $this->assertStatusNotIn($asset, [AssetStatus::ON_LOAN], 'diubah statusnya');
        $status = $data['to_status'] ?? null;
        $condition = $data['to_condition_id'] ?? null;
        $assetChanges = [];
        $logChanges = [];

        if ($status !== null && $status !== $asset->status) {
            $assetChanges['status'] = $status;
            $logChanges += ['from_status' => $asset->status, 'to_status' => $status];
        }
        if ($condition !== null && $condition !== $asset->condition_id) {
            $this->assertActiveCondition($condition);
            $assetChanges['condition_id'] = $condition;
            $logChanges += ['from_condition_id' => $asset->condition_id, 'to_condition_id' => $condition];
        }
        if ($assetChanges === []) {
            throw $this->fieldError('to_status', 'Pilih status atau kondisi baru yang berbeda dari saat ini.');
        }

        return [$assetChanges, $logChanges];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function loan(Asset $asset, array $data): array
    {
        if (! in_array($asset->status, [AssetStatus::AVAILABLE, AssetStatus::IN_USE], true)) {
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Hanya aset berstatus Tersedia atau Digunakan yang dapat dipinjamkan (status saat ini: '.AssetStatus::LABELS[$asset->status].').');
        }
        $borrower = trim((string) ($data['borrower_name'] ?? ''));
        if ($borrower === '') {
            throw $this->fieldError('borrower_name', 'Nama peminjam wajib diisi.');
        }
        $due = $data['due_date'] ?? null;
        if ($due !== null && $due < ($data['transaction_date'] ?? $this->log->today())) {
            throw $this->fieldError('due_date', 'Rencana tanggal kembali tidak boleh sebelum tanggal peminjaman.');
        }

        return [
            ['status' => AssetStatus::ON_LOAN, 'borrower_name' => $borrower, 'loan_due_date' => $due],
            ['from_status' => $asset->status, 'to_status' => AssetStatus::ON_LOAN, 'borrower_name' => $borrower, 'due_date' => $due],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function return(Asset $asset, array $data): array
    {
        if ($asset->status !== AssetStatus::ON_LOAN) {
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Aset ini tidak sedang dipinjam.');
        }
        $assetChanges = ['status' => AssetStatus::AVAILABLE, 'borrower_name' => null, 'loan_due_date' => null];
        $logChanges = ['from_status' => AssetStatus::ON_LOAN, 'to_status' => AssetStatus::AVAILABLE, 'borrower_name' => $asset->borrower_name];

        $location = $data['to_location_id'] ?? null;
        if ($location !== null && $location !== $asset->location_id) {
            $this->assertTargetLocation($location);
            $assetChanges['location_id'] = $location;
            $logChanges += ['from_location_id' => $asset->location_id, 'to_location_id' => $location];
        }
        $condition = $data['to_condition_id'] ?? null;
        if ($condition !== null && $condition !== $asset->condition_id) {
            $this->assertActiveCondition($condition);
            $assetChanges['condition_id'] = $condition;
            $logChanges += ['from_condition_id' => $asset->condition_id, 'to_condition_id' => $condition];
        }

        return [$assetChanges, $logChanges];
    }

    /** @param array<string, mixed> $data */
    private function authorize(string $type, array $data): void
    {
        $context = $this->tenancy->tenant();
        $permission = self::PERMISSIONS[$type] ?? null;
        if ($permission !== null && ! $context->can($permission)) {
            throw ApiException::forbidden();
        }
        if ($type === TransactionType::STATUS_CHANGE && ($data['to_status'] ?? null) === AssetStatus::DISPOSED && ! $context->can('asset.dispose')) {
            throw ApiException::forbidden('FORBIDDEN', 'Anda tidak memiliki izin untuk menghapuskan aset.');
        }
    }

    /** Lock the asset row; assets outside the tenant or the user's data scope are 404. */
    private function lockAsset(string $id): Asset
    {
        $asset = $this->scope->apply(Asset::query())->whereKey($id)->lockForUpdate()->first();
        if ($asset === null) {
            throw new ApiException('NOT_FOUND', 'Aset tidak ditemukan.', 404, ['fields' => ['asset_id' => ['Aset tidak ditemukan.']]]);
        }

        return $asset;
    }

    /** @param list<string> $statuses */
    private function assertStatusNotIn(Asset $asset, array $statuses, string $action): void
    {
        if (in_array($asset->status, $statuses, true)) {
            $hint = $asset->status === AssetStatus::ON_LOAN ? ' Catat pengembalian terlebih dahulu.' : '';
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Aset berstatus '.AssetStatus::LABELS[$asset->status]." tidak dapat {$action}.{$hint}");
        }
    }

    private function assertTargetLocation(string $id): void
    {
        if (! Location::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->exists()) {
            throw $this->fieldError('to_location_id', 'Lokasi tidak ditemukan atau tidak aktif.');
        }
        if (! $this->scope->allowsLocation($id)) {
            throw $this->fieldError('to_location_id', 'Lokasi berada di luar cakupan data Anda.');
        }
    }

    private function assertActiveCondition(string $id): void
    {
        if (! AssetCondition::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->exists()) {
            throw $this->fieldError('to_condition_id', 'Kondisi tidak ditemukan atau tidak aktif.');
        }
    }

    private function fieldError(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('VALIDATION_FAILED', $message, ['fields' => [$field => [$message]]]);
    }
}
