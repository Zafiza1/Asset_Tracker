<?php

namespace App\Domain\Approvals;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The submitted input of an approval request as readable rows for the approver: field labels
 * instead of keys, names instead of ids, labels instead of status codes. For changes of an existing
 * record (`*.update`) every row also carries the record's current value, so the approver sees
 * what changes before deciding.
 */
final class ApprovalDetails
{
    private const LABELS = [
        'asset_id' => 'Aset',
        'asset_ids' => 'Aset',
        'type' => 'Jenis transaksi',
        'transaction_date' => 'Tanggal transaksi',
        'to_location_id' => 'Lokasi tujuan',
        'to_status' => 'Status baru',
        'borrower_name' => 'Peminjam',
        'due_date' => 'Rencana tanggal kembali',
        'notes' => 'Keterangan',
        'reason' => 'Alasan',
        'code' => 'Kode',
        'name' => 'Nama',
        'label' => 'Nama atribut',
        'serial_number' => 'No seri',
        'category_id' => 'Kategori',
        'location_id' => 'Lokasi',
        'parent_id' => 'Induk',
        'status' => 'Status',
        'address' => 'Alamat',
        'description' => 'Deskripsi',
        'acquisition_date' => 'Tanggal perolehan',
        'acquisition_cost' => 'Biaya perolehan',
        'registered_on' => 'Tanggal pendaftaran',
        'attributes' => 'Atribut kategori',
        'data_type' => 'Tipe data',
        'is_required' => 'Wajib diisi',
        'options' => 'Pilihan',
    ];

    /** Technical input that means nothing to the approver. */
    private const HIDDEN = ['lock_version', 'generic_category_id', 'section', 'sort_order'];

    private const MASTER_STATUS = ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'archived' => 'Diarsipkan'];

    /** Model of the record an `<entity>.update` action changes. */
    private const SUBJECTS = [
        'asset' => Asset::class,
        'location' => Location::class,
        'category' => TenantAssetCategory::class,
        'department' => Department::class,
    ];

    /** @return list<array{label: string, value: string, current?: string}> */
    public static function rows(ApprovalRequest $approval): array
    {
        $payload = $approval->payload ?? [];
        $subject = self::subject($approval);
        $rows = [];
        foreach ($payload as $key => $value) {
            if ($key === 'changes' && is_array($value)) {
                foreach ($value as $field => $changed) {
                    self::add($rows, $approval, (string) $field, $changed, null);
                }

                continue;
            }
            self::add($rows, $approval, (string) $key, $value, $subject);
        }
        foreach ($approval->files ?? [] as $file) {
            $rows[] = ['label' => 'File', 'value' => $file['name']];
        }

        return $rows;
    }

    /** @param list<array{label: string, value: string, current?: string}> $rows */
    private static function add(array &$rows, ApprovalRequest $approval, string $key, mixed $value, ?Model $subject): void
    {
        if (in_array($key, self::HIDDEN, true)) {
            return;
        }
        // An asset's category attribute values are a long list of mostly empty fields; the
        // approver decides on the asset's main data. Category attribute changes still show them.
        if ($key === 'attributes' && ApprovalActions::entity($approval->action) === 'asset') {
            return;
        }
        $row = ['label' => self::LABELS[$key] ?? $key, 'value' => self::value($approval, $key, $value)];
        if ($subject !== null) {
            $row['current'] = self::value($approval, $key, self::currentValue($subject, $key));
        }
        $rows[] = $row;
    }

    /** The existing record an update request changes; null for other actions or when it is gone. */
    private static function subject(ApprovalRequest $approval): ?Model
    {
        [$entity, $verb] = array_pad(explode('.', $approval->action, 2), 2, '');
        $model = self::SUBJECTS[$entity] ?? null;
        if ($verb !== 'update' || $model === null || ! preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $approval->path, $m)) {
            return null;
        }

        return $model::query()->whereKey($m[0])->first();
    }

    private static function currentValue(Model $subject, string $key): mixed
    {
        if ($key === 'attributes' && $subject instanceof Asset) {
            return $subject->attribute_values;
        }
        if (! array_key_exists($key, $subject->getAttributes())) {
            return null;
        }
        $value = $subject->getAttribute($key);

        return match (true) {
            $value instanceof CarbonInterface => $value->toDateString(),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }

    private static function value(ApprovalRequest $approval, string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'asset_id', 'asset_ids' => self::assets((array) $value),
            'category_id' => self::name(TenantAssetCategory::class, $value),
            'location_id', 'to_location_id' => self::name(Location::class, $value),
            'parent_id' => self::name(ApprovalActions::entity($approval->action) === 'location' ? Location::class : TenantAssetCategory::class, $value),
            'type' => TransactionType::LABELS[$value] ?? (string) $value,
            'to_status' => AssetStatus::LABELS[$value] ?? (string) $value,
            'status' => AssetStatus::LABELS[$value] ?? self::MASTER_STATUS[$value] ?? (string) $value,
            'acquisition_cost' => is_numeric($value) ? 'Rp '.number_format((float) $value, 0, ',', '.') : (string) $value,
            'attributes' => self::attributes($value),
            default => self::scalar($value),
        };
    }

    /** @param class-string<Model> $model */
    private static function name(string $model, mixed $id): string
    {
        if (! is_string($id)) {
            return self::scalar($id);
        }
        $record = $model::query()->whereKey($id)->first(['id', 'code', 'name']);

        return $record === null ? '(tidak ditemukan)' : "{$record->name} ({$record->code})";
    }

    /** @param array<mixed> $ids */
    private static function assets(array $ids): string
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        $assets = Asset::query()->whereIn('id', array_slice($ids, 0, 20))->orderBy('asset_number')->get(['id', 'name']);
        $list = $assets->map(fn (Asset $a) => $a->name)->implode(', ');
        $more = count($ids) - $assets->count();

        return $list.($more > 0 ? " dan {$more} aset lain" : '');
    }

    private static function attributes(mixed $value): string
    {
        if (! is_array($value)) {
            return self::scalar($value);
        }
        // A category's full attribute list (sync), or {key: value} of an asset.
        if (array_is_list($value)) {
            return count($value).' atribut: '.implode(', ', array_map(fn ($a) => is_array($a) ? (string) ($a['label'] ?? '') : self::scalar($a), $value));
        }

        return implode('; ', array_map(fn ($k, $v) => "{$k}: ".self::scalar($v), array_keys($value), $value));
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_array($value) => implode(', ', array_map(fn ($v) => self::scalar($v), $value)),
            default => (string) $value,
        };
    }
}
