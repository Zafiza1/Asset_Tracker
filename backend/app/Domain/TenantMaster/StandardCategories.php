<?php

namespace App\Domain\TenantMaster;

use App\Domain\GenericMaster\Attributes\AttributeType;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Support\Str;

/**
 * The asset categories every organization starts with, each with its own default fields on top
 * of the general ones (CategoryAttributeService::DEFAULT_ATTRIBUTES). Merek, Model / Tipe and
 * Nomor Seri are general fields, so they are not repeated here: labels must stay unique per category.
 */
final class StandardCategories
{
    private const LOCATION = 'location_ownership';

    private const WATT = ['Watt', 'kW', 'HP'];

    /**
     * @return array<string, array{name: string, description: string, generic: string, attributes: list<array{label: string, data_type: string, section: string, is_required: bool, options?: list<array{value: string, label: string}>}>}>
     */
    public static function all(): array
    {
        return [
            'AST_IT' => [
                'name' => 'Aset IT & Elektronik',
                'description' => 'Laptop, komputer, printer, server, scanner.',
                'generic' => 'PERANGKAT_IT',
                'attributes' => [
                    self::text('Processor'),
                    self::text('Kapasitas RAM'),
                    self::text('Kapasitas Penyimpanan'),
                    self::select('Jenis Penyimpanan', ['HDD', 'SSD', 'NVMe', 'Hybrid']),
                    self::text('Sistem Operasi'),
                    self::text('Ukuran Layar'),
                    self::text('Alamat IP'),
                    self::text('MAC Address'),
                    self::integer('Tahun Produksi'),
                    self::date('Tanggal Garansi Berakhir'),
                ],
            ],
            'AST_KENDARAAN' => [
                'name' => 'Aset Kendaraan',
                'description' => 'Mobil, motor, truk, forklift.',
                'generic' => 'KENDARAAN',
                'attributes' => [
                    self::text('Nomor Polisi'),
                    self::text('Nomor Rangka'),
                    self::text('Nomor Mesin'),
                    self::select('Jenis Kendaraan', ['Mobil', 'Motor', 'Truk', 'Bus', 'Forklift', 'Alat Berat', 'Lainnya']),
                    self::integer('Tahun Pembuatan'),
                    self::text('Warna Kendaraan'),
                    self::decimal('Kapasitas Mesin (CC)'),
                    self::select('Jenis Bahan Bakar', ['Bensin', 'Solar', 'Listrik', 'Hybrid', 'Gas', 'Lainnya']),
                    self::decimal('Kapasitas Muatan'),
                    self::date('Tanggal STNK Berakhir'),
                    self::date('Tanggal KIR Berakhir'),
                    self::decimal('Kilometer / Odometer'),
                ],
            ],
            'AST_MESIN' => [
                'name' => 'Aset Mesin & Peralatan',
                'description' => 'Mesin produksi, kompresor, genset.',
                'generic' => 'MESIN',
                'attributes' => [
                    self::text('Nomor Mesin'),
                    self::text('Nomor Seri Pabrik'),
                    self::integer('Tahun Produksi'),
                    self::decimal('Kapasitas Mesin'),
                    self::select('Satuan Kapasitas', ['Liter/menit', 'm³/jam', 'ton/jam']),
                    self::decimal('Daya Mesin'),
                    self::select('Satuan Daya', self::WATT),
                    self::decimal('Tegangan Operasional'),
                    self::decimal('Frekuensi Operasional'),
                    self::date('Tanggal Instalasi'),
                    self::date('Jadwal Maintenance Berikutnya'),
                ],
            ],
            'AST_FURNITURE' => [
                'name' => 'Aset Furniture & Perlengkapan',
                'description' => 'Meja, kursi, lemari, rak.',
                'generic' => 'PERALATAN_KANTOR',
                'attributes' => [
                    self::select('Jenis Furniture', ['Meja', 'Kursi', 'Lemari', 'Rak', 'Sofa', 'Lainnya']),
                    self::text('Bahan Material'),
                    self::text('Warna'),
                    self::decimal('Panjang (cm)'),
                    self::decimal('Lebar (cm)'),
                    self::decimal('Tinggi (cm)'),
                    self::integer('Jumlah Unit'),
                    self::decimal('Kapasitas Beban (kg)'),
                    self::integer('Tahun Produksi'),
                ],
            ],
            'AST_PROPERTI' => [
                'name' => 'Aset Bangunan & Properti',
                'description' => 'Gedung, kantor, gudang, tanah.',
                'generic' => 'UMUM',
                'attributes' => [
                    self::select('Jenis Properti', ['Gedung', 'Kantor', 'Gudang', 'Tanah', 'Lainnya']),
                    self::field('Alamat Lengkap', AttributeType::TEXT, self::LOCATION),
                    self::decimal('Luas Tanah (m²)'),
                    self::decimal('Luas Bangunan (m²)'),
                    self::integer('Jumlah Lantai'),
                    self::integer('Tahun Pembangunan'),
                    self::text('Nomor Sertifikat'),
                    self::select('Jenis Kepemilikan', ['Milik Sendiri', 'Sewa', 'Hak Guna Bangunan', 'Hak Guna Usaha', 'Hak Pakai', 'Lainnya'], self::LOCATION),
                    self::text('Nomor IMB / PBG'),
                    self::date('Tanggal Berakhir Hak'),
                ],
            ],
            'AST_ALAT_KERJA' => [
                'name' => 'Aset Alat Kerja',
                'description' => 'Bor, gerinda, tang, obeng.',
                'generic' => 'UMUM',
                'attributes' => [
                    self::select('Jenis Alat', ['Bor', 'Gerinda', 'Tang', 'Obeng', 'Lainnya']),
                    self::text('Bahan Material'),
                    self::text('Ukuran Alat'),
                    self::decimal('Daya Alat'),
                    self::select('Satuan Daya', self::WATT),
                    self::select('Sumber Energi', ['Listrik', 'Baterai', 'Manual', 'Bahan Bakar']),
                    self::date('Tanggal Pembelian'),
                ],
            ],
            'AST_OPERASIONAL' => [
                'name' => 'Aset Operasional',
                'description' => 'Timbangan, hand pallet, trolley.',
                'generic' => 'UMUM',
                'attributes' => [
                    self::select('Jenis Peralatan', ['Timbangan', 'Hand Pallet', 'Trolley', 'Lainnya']),
                    self::decimal('Kapasitas Maksimum'),
                    self::select('Satuan Kapasitas', ['Kg', 'Ton', 'Liter', 'Unit']),
                    self::text('Material'),
                    self::integer('Tahun Produksi'),
                    self::date('Tanggal Kalibrasi Terakhir'),
                    self::date('Jadwal Kalibrasi Berikutnya'),
                    self::date('Jadwal Maintenance Berikutnya'),
                ],
            ],
            'AST_MEDIS' => [
                'name' => 'Aset Medis & Kesehatan',
                'description' => 'Bed pasien, tabung oksigen, monitor pasien.',
                'generic' => 'PERALATAN_MEDIS',
                'attributes' => [
                    self::select('Jenis Alat Medis', ['Bed Pasien', 'Tabung Oksigen', 'Monitor Pasien', 'Lainnya']),
                    self::select('Kelas Alat Medis', ['Kelas A', 'Kelas B', 'Kelas C', 'Kelas D']),
                    self::decimal('Kapasitas'),
                    self::select('Satuan Kapasitas', ['Liter', 'Kg', 'Unit']),
                    self::text('Nomor Izin Edar'),
                    self::date('Tanggal Kalibrasi Terakhir'),
                    self::date('Jadwal Kalibrasi Berikutnya'),
                    self::date('Tanggal Maintenance Berikutnya'),
                ],
            ],
            'AST_INFRASTRUKTUR' => [
                'name' => 'Aset Infrastruktur',
                'description' => 'Jaringan LAN, router, CCTV, access point.',
                'generic' => 'UMUM',
                'attributes' => [
                    self::select('Jenis Infrastruktur', ['Jaringan LAN', 'Router', 'CCTV', 'Access Point', 'Switch', 'Lainnya']),
                    self::text('Alamat IP'),
                    self::text('MAC Address'),
                    self::field('Lokasi Instalasi', AttributeType::STRING, self::LOCATION),
                    self::decimal('Kapasitas / Bandwidth'),
                    self::select('Satuan Bandwidth', ['Kbps', 'Mbps', 'Gbps']),
                    self::date('Tanggal Instalasi'),
                    self::date('Garansi Berakhir'),
                ],
            ],
            'AST_INVENTARIS' => [
                'name' => 'Aset Inventaris Umum',
                'description' => 'Proyektor, papan tulis, perlengkapan kantor.',
                'generic' => 'UMUM',
                'attributes' => [
                    self::select('Jenis Inventaris', ['Proyektor', 'Papan Tulis', 'Perlengkapan Kantor', 'Lainnya']),
                    self::text('Bahan Material'),
                    self::text('Warna'),
                    self::text('Ukuran'),
                    self::integer('Jumlah Unit'),
                    self::integer('Tahun Produksi'),
                ],
            ],
        ];
    }

    /** @param list<string> $labels */
    private static function select(string $label, array $labels, string $section = CategoryAttributeService::SECTION_SPECIFIC): array
    {
        return [...self::field($label, AttributeType::ENUM, $section), 'options' => array_map(
            fn (string $option) => ['value' => Str::upper(Str::slug($option, '_')), 'label' => $option],
            $labels,
        )];
    }

    private static function text(string $label): array
    {
        return self::field($label, AttributeType::STRING);
    }

    private static function integer(string $label): array
    {
        return self::field($label, AttributeType::INTEGER);
    }

    private static function decimal(string $label): array
    {
        return self::field($label, AttributeType::DECIMAL);
    }

    private static function date(string $label): array
    {
        return self::field($label, AttributeType::DATE);
    }

    private static function field(string $label, string $type, string $section = CategoryAttributeService::SECTION_SPECIFIC): array
    {
        return ['label' => $label, 'data_type' => $type, 'section' => $section, 'is_required' => false];
    }
}
