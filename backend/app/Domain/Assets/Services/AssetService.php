<?php

namespace App\Domain\Assets\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Numbering\NumberSequence;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Asset Management: register, edit descriptive data, deactivate (archive) and reactivate.
 * Each operation is one database transaction with its history entry and audit entry.
 * Location/status changes after registration belong to AssetTransactionService.
 */
final class AssetService
{
    private const AUDIT = ['asset_number', 'serial_number', 'name', 'category_id', 'location_id', 'status',
        'acquisition_date', 'acquisition_cost', 'description'];

    public function __construct(
        private readonly AssetScope $scope,
        private readonly TransactionLog $log,
        private readonly NumberSequence $numbers,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function create(array $data): Asset
    {
        $this->assertActive(TenantAssetCategory::class, $data['category_id'], 'category_id', 'Kategori');
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null) {
            $this->assertActive(Location::class, $locationId, 'location_id', 'Lokasi');
        }
        if (! $this->scope->allowsLocation($locationId)) {
            throw ApiException::unprocessable('LOCATION_OUT_OF_SCOPE', 'Lokasi berada di luar cakupan data Anda.', ['fields' => ['location_id' => ['Pilih lokasi dalam cakupan data Anda.']]]);
        }
        $this->assertSerialAvailable($data['serial_number'] ?? null);
        $status = $data['status'] ?? AssetStatus::AVAILABLE;

        return DB::transaction(function () use ($data, $locationId, $status) {
            $format = OrganizationSetting::query()->value('asset_number_format') ?: 'AST-{YYYY}-{SEQ:6}';
            $userId = $this->tenancy->tenant()->userId();

            $asset = new Asset;
            $asset->forceFill([
                ...array_intersect_key($data, array_flip(Asset::EDITABLE)),
                'asset_number' => $this->numbers->next('asset', $format),
                'location_id' => $locationId,
                'status' => $status,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();
            $asset->refresh();

            $this->log->record($asset, TransactionType::REGISTRATION, [
                'to_location_id' => $locationId,
                'to_status' => $status,
            ], $data['registered_on'] ?? null, $data['notes'] ?? null);
            $this->audit->record('asset.created', $asset, after: $this->auditable($asset));

            return $asset;
        });
    }

    /** @param array<string, mixed> $data validated input; lock_version optional */
    public function update(Asset $asset, array $data): Asset
    {
        $this->assertNotArchived($asset);
        if (isset($data['lock_version']) && (int) $data['lock_version'] !== $asset->lock_version) {
            throw ApiException::conflict('STALE_ASSET', 'Data aset telah diubah oleh pengguna lain. Muat ulang lalu coba lagi.');
        }
        if (isset($data['category_id']) && $data['category_id'] !== $asset->category_id) {
            $this->assertActive(TenantAssetCategory::class, $data['category_id'], 'category_id', 'Kategori');
        }
        if (array_key_exists('serial_number', $data)) {
            $this->assertSerialAvailable($data['serial_number'], $asset->id);
        }
        $changes = array_intersect_key($data, array_flip(Asset::EDITABLE));

        return DB::transaction(function () use ($asset, $changes) {
            $before = $this->auditable($asset);
            $asset->forceFill($changes);
            if (! $asset->isDirty()) {
                return $asset;
            }
            $asset->forceFill(['lock_version' => $asset->lock_version + 1, 'updated_by' => $this->tenancy->tenant()->userId()])->save();
            [$b, $a] = AuditLogger::diff($before, $this->auditable($asset));
            $this->audit->record('asset.updated', $asset, before: $b, after: $a);

            return $asset;
        });
    }

    /** Deactivate: the asset leaves the active inventory; number, data and history are kept. */
    public function archive(Asset $asset, ?string $reason): Asset
    {
        $this->assertNotArchived($asset);
        if ($asset->status === AssetStatus::ON_LOAN) {
            throw ApiException::conflict('ASSET_ON_LOAN', 'Aset yang sedang dipinjam harus dikembalikan sebelum dinonaktifkan.');
        }

        return DB::transaction(function () use ($asset, $reason) {
            $userId = $this->tenancy->tenant()->userId();
            $asset->forceFill(['archived_at' => now(), 'archived_by' => $userId, 'updated_by' => $userId, 'lock_version' => $asset->lock_version + 1])->save();
            $this->log->record($asset, TransactionType::ARCHIVE, notes: $reason);
            $this->audit->record('asset.archived', $asset, metadata: $reason ? ['reason' => $reason] : []);

            return $asset;
        });
    }

    public function restore(Asset $asset): Asset
    {
        if (! $asset->isArchived()) {
            throw ApiException::conflict('NOT_ARCHIVED', 'Aset tidak dalam keadaan nonaktif.');
        }

        return DB::transaction(function () use ($asset) {
            $asset->forceFill(['archived_at' => null, 'archived_by' => null, 'updated_by' => $this->tenancy->tenant()->userId(), 'lock_version' => $asset->lock_version + 1])->save();
            $this->log->record($asset, TransactionType::RESTORE);
            $this->audit->record('asset.restored', $asset);

            return $asset;
        });
    }

    public function assertNotArchived(Asset $asset): void
    {
        if ($asset->isArchived()) {
            throw ApiException::conflict('ASSET_ARCHIVED', 'Aset nonaktif tidak dapat diubah. Aktifkan kembali terlebih dahulu.');
        }
    }

    /** @param class-string<Model> $model */
    private function assertActive(string $model, string $id, string $field, string $label): void
    {
        if (! $model::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', "{$label} tidak ditemukan atau tidak aktif.", ['fields' => [$field => ["{$label} tidak ditemukan atau tidak aktif."]]]);
        }
    }

    private function assertSerialAvailable(?string $serial, ?string $exceptId = null): void
    {
        if ($serial === null || $serial === '') {
            return;
        }
        $taken = Asset::query()->whereRaw('lower(serial_number) = lower(?)', [$serial])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => ['serial_number' => ['Nomor seri/kode sudah dipakai aset lain.']]]);
        }
    }

    /** @return array<string, mixed> */
    private function auditable(Asset $asset): array
    {
        $values = $asset->only(self::AUDIT);
        $values['acquisition_date'] = $asset->acquisition_date?->toDateString();

        return $values;
    }
}
