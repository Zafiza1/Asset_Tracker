<?php

namespace App\Domain\ReportDesigner\Catalog;

/**
 * Ready-made report templates (F-04): defined in code, copied into an organization as a draft
 * template that the organization then owns and adapts in the Report Designer. Each design
 * carries default filters (design.filters) that the user can still override.
 */
final class SampleTemplates
{
    private const LANDSCAPE = ['size' => 'A4', 'orientation' => 'landscape', 'marginTop' => 15, 'marginRight' => 15, 'marginBottom' => 15, 'marginLeft' => 15];

    private const PORTRAIT = ['size' => 'A4', 'orientation' => 'portrait', 'marginTop' => 20, 'marginRight' => 20, 'marginBottom' => 20, 'marginLeft' => 20];

    /** @return array<string, array{code: string, name: string, description: string, category: string, data_source: string, design: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            'outgoing' => [
                'code' => 'RPT-TRX-KELUAR',
                'name' => 'Laporan Transaksi Keluar',
                'description' => 'Aset yang keluar dari business unit: sifat, alasan, pihak luar, dokumen, dan status kembali.',
                'category' => 'Transaksi',
                'data_source' => 'asset_transaction',
                'design' => self::listing(['type' => 'outgoing'], [
                    ['transaction.number', 'No. Transaksi'],
                    ['transaction.date', 'Tanggal'],
                    ['transaction.asset_name', 'Aset'],
                    ['transaction.asset_serial', 'No. Seri'],
                    ['transaction.outgoing_nature', 'Sifat'],
                    ['transaction.reason', 'Alasan'],
                    ['transaction.counterparty_name', 'Pihak Luar'],
                    ['transaction.counterparty_org', 'Instansi'],
                    ['transaction.document_number', 'No. Dokumen'],
                    ['transaction.due_date', 'Rencana Kembali'],
                    ['transaction.return_status', 'Status Kembali'],
                ]),
            ],
            'incoming' => [
                'code' => 'RPT-TRX-MASUK',
                'name' => 'Laporan Transaksi Masuk',
                'description' => 'Aset yang kembali masuk ke business unit beserta transaksi keluarnya.',
                'category' => 'Transaksi',
                'data_source' => 'asset_transaction',
                'design' => self::listing(['type' => 'incoming'], [
                    ['transaction.number', 'No. Transaksi'],
                    ['transaction.date', 'Tanggal Masuk'],
                    ['transaction.asset_name', 'Aset'],
                    ['transaction.asset_serial', 'No. Seri'],
                    ['transaction.outgoing_number', 'No. Transaksi Keluar'],
                    ['transaction.counterparty_name', 'Diterima Dari'],
                    ['transaction.to_status', 'Status Saat Kembali'],
                    ['transaction.to_location', 'Lokasi Masuk'],
                    ['transaction.document_number', 'No. Dokumen'],
                ]),
            ],
            'not_returned' => [
                'code' => 'RPT-ASET-BELUM-KEMBALI',
                'name' => 'Aset Belum Kembali',
                'description' => 'Aset yang keluar sementara dan belum dicatat masuk kembali, termasuk yang terlambat.',
                'category' => 'Transaksi',
                'data_source' => 'asset_transaction',
                'design' => self::listing(['not_returned' => true], [
                    ['transaction.number', 'No. Transaksi'],
                    ['transaction.date', 'Tanggal Keluar'],
                    ['transaction.asset_name', 'Aset'],
                    ['transaction.asset_serial', 'No. Seri'],
                    ['transaction.reason', 'Alasan'],
                    ['transaction.counterparty_name', 'Pihak Luar'],
                    ['transaction.counterparty_org', 'Instansi'],
                    ['transaction.due_date', 'Rencana Kembali'],
                    ['transaction.days_out', 'Lama (hari)'],
                    ['transaction.return_status', 'Status'],
                ]),
            ],
            'handover' => [
                'code' => 'RPT-BAST-KELUAR',
                'name' => 'Bukti Serah Terima (BAST) Aset Keluar',
                'description' => 'Berita acara serah terima untuk satu transaksi keluar. Cari nomor transaksinya di filter "Pencarian".',
                'category' => 'Transaksi',
                'data_source' => 'asset_transaction',
                'design' => self::handover(),
            ],
        ];
    }

    /** @return list<array{key: string, code: string, name: string, description: string, category: string, data_source: string}> */
    public static function summaries(): array
    {
        $out = [];
        foreach (self::all() as $key => $sample) {
            $out[] = ['key' => $key] + array_diff_key($sample, ['design' => true]);
        }

        return $out;
    }

    /**
     * Landscape list: title, organization, then one table.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<array{0: string, 1: string}>  $columns
     * @return array<string, mixed>
     */
    private static function listing(array $filters, array $columns): array
    {
        return [
            'schema' => 1,
            'page' => self::LANDSCAPE,
            'filters' => $filters,
            'elements' => [
                self::text('title', '{{report.title}}', 0, 0, 267, 10, ['fontSize' => 16, 'fontWeight' => 'bold'], 1),
                self::text('org', '{{organization.name}} · dicetak {{report.generated_at}}', 0, 11, 267, 7, ['fontSize' => 9], 2),
                [
                    'id' => 'rows', 'type' => 'table', 'x' => 0, 'y' => 22, 'w' => 267, 'h' => 150, 'z' => 3,
                    'style' => ['fontSize' => 8],
                    'props' => ['columns' => array_map(fn (array $c) => ['key' => $c[0], 'label' => $c[1]], $columns)],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function handover(): array
    {
        return [
            'schema' => 1,
            'page' => self::PORTRAIT,
            'filters' => ['type' => 'outgoing'],
            'elements' => [
                self::text('title', 'BERITA ACARA SERAH TERIMA ASET', 0, 0, 170, 10, ['fontSize' => 14, 'fontWeight' => 'bold', 'textAlign' => 'center'], 1),
                self::text('org', '{{organization.name}}', 0, 11, 170, 7, ['fontSize' => 10, 'textAlign' => 'center'], 2),
                self::text('intro', 'Dengan ini pihak pertama menyerahkan aset berikut kepada pihak kedua:', 0, 24, 170, 8, ['fontSize' => 10], 3),
                [
                    'id' => 'rows', 'type' => 'table', 'x' => 0, 'y' => 34, 'w' => 170, 'h' => 120, 'z' => 4,
                    'style' => ['fontSize' => 9],
                    'props' => ['columns' => [
                        ['key' => 'transaction.number', 'label' => 'No. Transaksi'],
                        ['key' => 'transaction.date', 'label' => 'Tanggal'],
                        ['key' => 'transaction.asset_name', 'label' => 'Aset'],
                        ['key' => 'transaction.asset_serial', 'label' => 'No. Seri'],
                        ['key' => 'transaction.counterparty_name', 'label' => 'Penerima'],
                        ['key' => 'transaction.document_number', 'label' => 'No. Dokumen'],
                    ]],
                ],
                self::text('giver', 'Yang Menyerahkan,', 0, 165, 80, 7, ['fontSize' => 10, 'textAlign' => 'center'], 5),
                self::text('receiver', 'Yang Menerima,', 90, 165, 80, 7, ['fontSize' => 10, 'textAlign' => 'center'], 6),
                ['id' => 'giver_line', 'type' => 'line', 'x' => 10, 'y' => 195, 'w' => 60, 'h' => 0, 'z' => 7, 'style' => [], 'props' => []],
                ['id' => 'receiver_line', 'type' => 'line', 'x' => 100, 'y' => 195, 'w' => 60, 'h' => 0, 'z' => 8, 'style' => [], 'props' => []],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function text(string $id, string $text, float $x, float $y, float $w, float $h, array $style, int $z): array
    {
        return ['id' => $id, 'type' => str_contains($text, '{{') ? 'dynamic_text' : 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'z' => $z, 'style' => $style, 'props' => ['text' => $text]];
    }
}
