<?php

namespace App\Domain\Assets\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Asset Management: register, edit descriptive data, deactivate (archive) and reactivate.
 * Each operation is one database transaction with its history entry and audit entry.
 * Location/status changes after registration belong to AssetTransactionService.
 */
final class AssetService
{
    private const AUDIT = ['serial_number', 'name', 'category_id', 'location_id', 'status',
        'acquisition_date', 'acquisition_cost', 'description', 'custodian_user_id', 'department_id'];

    /** Private disk: asset photos are served only through the (scoped) asset route. */
    public const IMAGE_DISK = 'local';

    public function __construct(
        private readonly AssetScope $scope,
        private readonly TransactionLog $log,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
        private readonly CategoryAttributeService $attributes,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function create(array $data): Asset
    {
        $this->assertActive(TenantAssetCategory::class, $data['category_id'], 'category_id', 'Kategori');
        $locationId = $data['location_id'] ?? null;
        if ($locationId !== null) {
            $this->assertActive(Location::class, $locationId, 'location_id', 'Lokasi');
        }
        if (! $this->scope->allowsAsset($locationId, $data['department_id'] ?? null)) {
            throw self::outOfScope($this->scope->hasDepartments());
        }
        $this->assertSerialAvailable($data['serial_number'] ?? null);
        $this->assertResponsibility($data);
        $status = $data['status'] ?? AssetStatus::IN_USE;
        $hidden = $this->attributes->resolveHidden($data['category_id'], $data['hidden_attributes'] ?? []);
        $values = $this->attributes->resolveValues($data['category_id'], [], $data['attributes'] ?? [], $hidden);
        $extras = $this->attributes->resolveExtras($data['category_id'], $hidden, $data['extra_attributes'] ?? []);

        return DB::transaction(function () use ($data, $locationId, $status, $values, $hidden, $extras) {
            $userId = $this->tenancy->tenant()->userId();

            $asset = new Asset;
            $asset->forceFill([
                ...array_intersect_key($data, array_flip(Asset::EDITABLE)),
                'location_id' => $locationId,
                'status' => $status,
                'attribute_values' => $values,
                'hidden_attributes' => $hidden,
                'extra_attributes' => $extras,
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
        // Only a new choice must be active: an asset keeps a custodian who has since left.
        $this->assertResponsibility(array_filter(
            array_intersect_key($data, array_flip(['custodian_user_id', 'department_id'])),
            fn ($id, $field) => $id !== $asset->getAttribute($field),
            ARRAY_FILTER_USE_BOTH,
        ));
        // F-09 §3.3: a new department must keep the asset within the user's own scope.
        if (array_key_exists('department_id', $data) && $data['department_id'] !== $asset->department_id
            && ! $this->scope->allowsAsset($asset->location_id, $data['department_id'])) {
            throw self::outOfScope(true);
        }
        $changes = array_intersect_key($data, array_flip(Asset::EDITABLE));
        // Values are revalidated when they are sent or when the category (hence the set of fields) changes.
        $categoryId = $changes['category_id'] ?? $asset->category_id;
        $fieldsChange = array_key_exists('attributes', $data) || array_key_exists('hidden_attributes', $data) || array_key_exists('extra_attributes', $data);
        if ($fieldsChange || $categoryId !== $asset->category_id) {
            $hidden = $this->attributes->resolveHidden($categoryId, $data['hidden_attributes'] ?? $asset->hidden_attributes ?? []);
            $current = $categoryId === $asset->category_id ? $asset->attribute_values
                : $this->attributes->carryOver($asset->category_id, $categoryId, $asset->attribute_values);
            $values = $this->attributes->resolveValues($categoryId, $current, $data['attributes'] ?? null, $hidden);
            if (! self::sameValues($values, $asset->attribute_values)) {
                $changes['attribute_values'] = $values;
            }
            if ($hidden !== ($asset->hidden_attributes ?? [])) {
                $changes['hidden_attributes'] = $hidden;
            }
            $extras = $this->attributes->resolveExtras($categoryId, $hidden, $data['extra_attributes'] ?? $asset->extra_attributes ?? []);
            // jsonb keeps no key order, so the fields are compared by their parts.
            $parts = fn (array $list) => array_map(fn (array $e) => [$e['label'] ?? null, $e['data_type'] ?? null, $e['value'] ?? null], $list);
            if ($parts($extras) !== $parts($asset->extra_attributes ?? [])) {
                $changes['extra_attributes'] = $extras;
            }
        }

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

    /**
     * Replaces the asset's photo, or removes it when `$image` is null. The new file is written
     * before the row changes and the old one deleted only after the commit, so a failure never
     * leaves the asset pointing at a missing file.
     */
    public function setImage(Asset $asset, ?UploadedFile $image): Asset
    {
        return $this->replaceFile($asset, $image, 'image_path', [], 'asset.image', "assets/{$asset->organization_id}/{$asset->id}");
    }

    /** Replaces the asset's document, or removes it when `$document` is null; as setImage(). */
    public function setDocument(Asset $asset, ?UploadedFile $document): Asset
    {
        $name = $document === null ? null : mb_substr($document->getClientOriginalName(), 0, 255);

        return $this->replaceFile($asset, $document, 'document_path', ['document_name' => $name], 'asset.document', "assets/{$asset->organization_id}/{$asset->id}/documents");
    }

    /** @param array<string, mixed> $extra other columns that change along with the file */
    private function replaceFile(Asset $asset, ?UploadedFile $file, string $column, array $extra, string $auditPrefix, string $folder): Asset
    {
        $this->assertNotArchived($asset);
        if ($file === null && $asset->getAttribute($column) === null) {
            return $asset;
        }
        $disk = Storage::disk(self::IMAGE_DISK);
        $old = $asset->getAttribute($column);
        $new = $file?->store($folder, self::IMAGE_DISK);
        if ($new === false) {
            throw ApiException::unprocessable('UPLOAD_FAILED', 'File gagal disimpan. Coba lagi.');
        }

        try {
            DB::transaction(function () use ($asset, $new, $column, $extra, $auditPrefix) {
                $asset->forceFill([...$extra, $column => $new, 'lock_version' => $asset->lock_version + 1, 'updated_by' => $this->tenancy->tenant()->userId()])->save();
                $this->audit->record($auditPrefix.($new === null ? '_removed' : '_updated'), $asset);
            });
        } catch (\Throwable $e) {
            if ($new !== null) {
                $disk->delete($new);
            }
            throw $e;
        }
        if ($old !== null) {
            $disk->delete($old);
        }

        return $asset;
    }

    /** Deactivate: the asset leaves the active inventory; number, data and history are kept. */
    public function archive(Asset $asset, ?string $reason): Asset
    {
        $this->assertNotArchived($asset);
        if ($asset->status === AssetStatus::ON_LOAN) {
            throw ApiException::conflict('ASSET_ON_LOAN', 'Aset yang sedang dipinjam harus dikembalikan sebelum dinonaktifkan.');
        }
        if ($asset->status === AssetStatus::OFF_SITE) {
            throw ApiException::conflict('ASSET_OFF_SITE', 'Aset yang sedang di luar organisasi harus dicatat masuk kembali sebelum dinonaktifkan.');
        }

        return DB::transaction(function () use ($asset, $reason) {
            // An asset held by a running flow transaction stays until that one ends (F-07b, D-29).
            AssetTransactionService::assertNotHeldByRun([$asset]);
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

    /**
     * A chosen custodian is an active member (with an active account) of this organization and a
     * chosen department an active department. NULL (cleared) always passes.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertResponsibility(array $data): void
    {
        if (($data['department_id'] ?? null) !== null) {
            $this->assertActive(Department::class, $data['department_id'], 'department_id', 'Departemen');
        }
        $userId = $data['custodian_user_id'] ?? null;
        if ($userId === null) {
            return;
        }
        $active = OrganizationMember::query()->where('user_id', $userId)->where('status', User::STATUS_ACTIVE)
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))->exists();
        if (! $active) {
            $message = 'Penanggung jawab harus anggota aktif organisasi ini.';
            throw ApiException::unprocessable('INVALID_REFERENCE', $message, ['fields' => ['custodian_user_id' => [$message]]]);
        }
    }

    /** The asset would end up outside the user's data scope (F-09 §3.3). */
    private static function outOfScope(bool $byDepartment): ApiException
    {
        if ($byDepartment) {
            $message = 'Departemen atau lokasi aset harus berada dalam cakupan data Anda.';

            return ApiException::unprocessable('ASSET_OUT_OF_SCOPE', $message, ['fields' => ['department_id' => [$message]]]);
        }

        return ApiException::unprocessable('LOCATION_OUT_OF_SCOPE', 'Lokasi berada di luar cakupan data Anda.', ['fields' => ['location_id' => ['Pilih lokasi dalam cakupan data Anda.']]]);
    }

    private function assertSerialAvailable(?string $serial, ?string $exceptId = null): void
    {
        if ($serial === null || $serial === '') {
            return;
        }
        $taken = Asset::query()->whereRaw('lower(serial_number) = lower(?)', [$serial])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => ['serial_number' => ['No seri sudah dipakai aset lain.']]]);
        }
    }

    /** @return array<string, mixed> */
    private function auditable(Asset $asset): array
    {
        $values = $asset->only(self::AUDIT);
        $values['acquisition_date'] = $asset->acquisition_date?->toDateString();
        $values['attributes'] = $asset->attribute_values;
        $values['hidden_attributes'] = $asset->hidden_attributes ?? [];
        $values['extra_attributes'] = $asset->extra_attributes ?? [];

        return $values;
    }

    /**
     * Key order differs between freshly built and stored (jsonb) values.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameValues(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }
}
