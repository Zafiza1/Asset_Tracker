<?php

namespace App\Domain\ReportDesigner\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single source of truth for the report field registry, synced into
 * `report_field_registry` by ReportFieldRegistrySeeder. Templates may only bind fields
 * listed here: the registry — never template input — decides which columns, relations and
 * permissions a report can reach. There is no free-form SQL or table access.
 *
 * Data sources (V1, the modules that actually exist):
 *  - asset:            the asset register (same rows as the Asset report, data-scope filtered)
 *  - asset_transaction: the asset transaction history (one row per asset per transaction)
 *  - context:          organization-level values available to every template
 *                      ({{organization.name}} etc.); not a row source itself
 *
 * Field shape: [label, data_type, bind_path, relation?, formats?, required_permission?]
 *  - bind_path: dot path the resolver walks from the source root; 'items[].x' marks a
 *    repeatable child (none in V1 — transactions are flat per-asset rows).
 *  - formats: formatting hints interpreted by the renderer (date format, currency,
 *    number format, empty-value fallback). Never executable code.
 */
final class FieldCatalog
{
    public const DATA_SOURCES = ['asset', 'asset_transaction', 'context'];

    /** @return array<string, array<string, array{0: string, 1: string, 2: string, 3?: string|null, 4?: array<string, mixed>, 5?: string|null}>> */
    public static function all(): array
    {
        $date = ['date' => 'd/m/Y'];
        $money = ['currency' => 'IDR', 'decimals' => 0];
        $fallback = ['fallback' => '-'];

        return [
            'asset' => [
                'asset.asset_number' => ['Nomor Aset', 'string', 'asset_number', null, $fallback, 'asset.view'],
                'asset.name' => ['Nama Aset', 'string', 'name', null, $fallback, 'asset.view'],
                'asset.serial_number' => ['Nomor Seri', 'string', 'serial_number', null, $fallback, 'asset.view'],
                'asset.status' => ['Status', 'string', 'status', null, ['labels' => true, 'fallback' => '-'], 'asset.view'],
                'asset.category' => ['Kategori', 'string', 'category.name', 'category', $fallback, 'asset.view'],
                'asset.category_code' => ['Kode Kategori', 'string', 'category.code', 'category', $fallback, 'asset.view'],
                'asset.location' => ['Lokasi', 'string', 'location.name', 'location', $fallback, 'asset.view'],
                'asset.acquisition_date' => ['Tanggal Perolehan', 'date', 'acquisition_date', null, $date + $fallback, 'asset.view'],
                'asset.acquisition_cost' => ['Nilai Perolehan', 'currency', 'acquisition_cost', null, $money + $fallback, 'asset.view'],
                'asset.borrower_name' => ['Peminjam', 'string', 'borrower_name', null, $fallback, 'asset.view'],
                'asset.loan_due_date' => ['Jatuh Tempo Pinjam', 'date', 'loan_due_date', null, $date + $fallback, 'asset.view'],
                'asset.description' => ['Keterangan', 'string', 'description', null, $fallback, 'asset.view'],
                'asset.image' => ['Foto Aset', 'image', 'image_path', null, [], 'asset.view'],
                'asset.attributes' => ['Atribut Kustom', 'array', 'attribute_values', null, [], 'asset.view'],
                'asset.created_at' => ['Tanggal Registrasi', 'date', 'created_at', null, ['date' => 'd/m/Y H:i'] + $fallback, 'asset.view'],
            ],
            'asset_transaction' => [
                'transaction.number' => ['Nomor Transaksi', 'string', 'transaction_number', null, $fallback, 'transaction.view'],
                'transaction.date' => ['Tanggal Transaksi', 'date', 'transaction_date', null, $date + $fallback, 'transaction.view'],
                'transaction.type' => ['Jenis Transaksi', 'string', 'type', null, ['labels' => true, 'fallback' => '-'], 'transaction.view'],
                'transaction.notes' => ['Catatan', 'string', 'notes', null, $fallback, 'transaction.view'],
                'transaction.borrower_name' => ['Peminjam', 'string', 'borrower_name', null, $fallback, 'transaction.view'],
                'transaction.due_date' => ['Jatuh Tempo', 'date', 'due_date', null, $date + $fallback, 'transaction.view'],
                'transaction.from_status' => ['Status Sebelum', 'string', 'from_status', null, ['labels' => true, 'fallback' => '-'], 'transaction.view'],
                'transaction.to_status' => ['Status Sesudah', 'string', 'to_status', null, ['labels' => true, 'fallback' => '-'], 'transaction.view'],
                'transaction.from_location' => ['Lokasi Asal', 'string', 'from_location.name', 'from_location', $fallback, 'transaction.view'],
                'transaction.to_location' => ['Lokasi Tujuan', 'string', 'to_location.name', 'to_location', $fallback, 'transaction.view'],
                'transaction.created_at' => ['Dicatat Pada', 'date', 'created_at', null, ['date' => 'd/m/Y H:i'] + $fallback, 'transaction.view'],
                'transaction.creator' => ['Dicatat Oleh', 'string', 'creator.name', 'creator', $fallback, 'transaction.view'],
                'transaction.asset_number' => ['Nomor Aset', 'string', 'asset.asset_number', 'asset', $fallback, 'transaction.view'],
                'transaction.asset_name' => ['Nama Aset', 'string', 'asset.name', 'asset', $fallback, 'transaction.view'],
                'transaction.asset_serial' => ['Nomor Seri Aset', 'string', 'asset.serial_number', 'asset', $fallback, 'transaction.view'],
                'transaction.asset_category' => ['Kategori Aset', 'string', 'asset.category.name', 'asset.category', $fallback, 'transaction.view'],
                'transaction.asset_location' => ['Lokasi Aset', 'string', 'asset.location.name', 'asset.location', $fallback, 'transaction.view'],
                // Outgoing / incoming (F-04). Contact is personal data: its own permission (APR-0006 D-10).
                'transaction.outgoing_nature' => ['Sifat Keluar', 'string', 'outgoing_nature', null, ['labels' => true, 'fallback' => '-'], 'transaction.view'],
                'transaction.reason' => ['Alasan Keluar', 'string', 'reason_label', null, $fallback, 'transaction.view'],
                'transaction.counterparty_name' => ['Pihak Luar', 'string', 'counterparty_name', null, $fallback, 'transaction.view'],
                'transaction.counterparty_org' => ['Instansi Pihak Luar', 'string', 'counterparty_org', null, $fallback, 'transaction.view'],
                'transaction.counterparty_contact' => ['Kontak Pihak Luar', 'string', 'counterparty_contact', null, $fallback, 'transaction.contact.view'],
                'transaction.document_number' => ['Nomor Dokumen', 'string', 'document_number', null, $fallback, 'transaction.view'],
                'transaction.returned_on' => ['Tanggal Kembali', 'date', 'incoming_transaction.transaction_date', 'incoming_transaction', $date + $fallback, 'transaction.view'],
                'transaction.return_status' => ['Status Kembali', 'string', 'return_status', null, $fallback, 'transaction.view'],
                'transaction.days_out' => ['Lama di Luar (hari)', 'number', 'days_out', null, ['decimals' => 0] + $fallback, 'transaction.view'],
                'transaction.outgoing_number' => ['Nomor Transaksi Keluar', 'string', 'outgoing_transaction.transaction_number', 'outgoing_transaction', $fallback, 'transaction.view'],
            ],
            'context' => [
                'organization.name' => ['Nama Organisasi', 'string', 'organization.name', null, $fallback, null],
                'organization.code' => ['Kode Organisasi', 'string', 'organization.code', null, $fallback, null],
                'report.generated_at' => ['Waktu Generate', 'date', 'generated_at', null, ['date' => 'd/m/Y H:i'] + $fallback, null],
                'report.title' => ['Judul Laporan', 'string', 'report.title', null, $fallback, null],
            ],
        ];
    }

    /**
     * Field keys of one source, for validation and the fields API. Besides the static
     * registry this merges the organization's own transaction-type fields
     * (TransactionReportFields, F-07g) — dynamic entries that exist only in a tenant context.
     */
    public static function fields(string $source): array
    {
        $fields = self::all()[$source] ?? [];
        if ($source === 'asset_transaction') {
            $fields += app(TransactionReportFields::class)->fields();
        }

        return $fields;
    }

    public static function has(string $source, string $fieldKey): bool
    {
        return isset(self::fields($source)[$fieldKey]);
    }

    /** Idempotent sync into report_field_registry; runs in the system context (seeder). */
    public static function sync(): void
    {
        $now = now();
        $existing = DB::table('report_field_registry')->get()->keyBy(fn ($row) => $row->data_source.'|'.$row->field_key);
        $rows = [];
        $order = 0;
        foreach (self::all() as $source => $fields) {
            foreach ($fields as $key => [$label, $type, $bind, $relation, $formats, $permission]) {
                $unique = $source.'|'.$key;
                $rows[] = [
                    'id' => $existing[$unique]->id ?? (string) Str::uuid7(),
                    'data_source' => $source,
                    'field_key' => $key,
                    'label' => $label,
                    'data_type' => $type,
                    'bind_path' => $bind,
                    'relation' => $relation,
                    'is_repeatable' => str_contains($bind, '[]'),
                    // Cast to object so an empty map encodes as {} (the CHECK wants a JSON object).
                    'formats' => json_encode((object) ($formats ?? [])),
                    'required_permission' => $permission,
                    'sort_order' => $order++,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('report_field_registry')->upsert($rows, ['data_source', 'field_key'],
            ['label', 'data_type', 'bind_path', 'relation', 'is_repeatable', 'formats', 'required_permission', 'sort_order', 'is_active', 'updated_at']);
        $keep = array_map(fn ($r) => $r['data_source'].'|'.$r['field_key'], $rows);
        $stale = DB::table('report_field_registry')->get()
            ->reject(fn ($row) => in_array($row->data_source.'|'.$row->field_key, $keep, true))
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            DB::table('report_field_registry')->whereIn('id', $stale)->update(['is_active' => false, 'updated_at' => $now]);
        }
    }
}
