<?php

namespace Database\Seeders;

use App\Domain\GenericMaster\Attributes\AttributePayload;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\GenericMaster\Models\Unit;
use App\Domain\TenantMaster\Schema\SchemaResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Baseline Generic Master catalog: categories and definitions with a released v1.
 * Only creates what is missing; released versions are immutable, so an existing
 * definition is never modified here (changes go through a new version).
 */
class GenericCatalogSeeder extends Seeder
{
    private const CATEGORIES = [
        'UMUM' => 'Aset Umum',
        'KENDARAAN' => 'Kendaraan',
        'MESIN' => 'Mesin & Peralatan Industri',
        'PERALATAN_KANTOR' => 'Peralatan Kantor',
        'PERALATAN_MEDIS' => 'Peralatan Medis',
        'PERANGKAT_IT' => 'Perangkat IT',
        'GAS' => 'Tabung & Peralatan Gas',
        'KONSTRUKSI' => 'Peralatan Konstruksi',
        'ASET_DIGITAL' => 'Aset Digital',
    ];

    private const LABEL_HELP = ['label', 'help_text'];

    public function run(AttributePayload $payload, SchemaResolver $resolver): void
    {
        $categories = [];
        $order = 0;
        foreach (self::CATEGORIES as $code => $name) {
            $categories[$code] = GenericAssetCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => ++$order, 'status' => 'active']);
        }
        $units = Unit::query()->pluck('id', 'code');

        foreach ($this->definitions() as $code => $spec) {
            if (GenericAssetDefinition::query()->where('code', $code)->exists()) {
                continue;
            }
            DB::transaction(function () use ($code, $spec, $categories, $units, $payload, $resolver) {
                $definition = new GenericAssetDefinition;
                $definition->forceFill([
                    'category_id' => $categories[$spec['category']]->id,
                    'code' => $code,
                    'name' => $spec['name'],
                    'description' => $spec['description'],
                    'status' => 'active',
                ])->save();

                $version = new GenericDefinitionVersion;
                $version->forceFill([
                    'definition_id' => $definition->id,
                    'version_number' => 1,
                    'status' => GenericDefinitionVersion::DRAFT,
                    'changelog' => 'Versi awal katalog standar.',
                ])->save();

                foreach ($spec['attributes'] as $i => $attribute) {
                    $attribute['unit_id'] = isset($attribute['unit']) ? $units[$attribute['unit']] : null;
                    unset($attribute['unit']);
                    $columns = $payload->prepare(
                        [...$attribute, 'sort_order' => ($i + 1) * 10],
                        null, GenericAttributeDefinition::COPYABLE, withOverridable: true,
                    );
                    (new GenericAttributeDefinition)->forceFill([...$columns, 'definition_version_id' => $version->id])->save();
                }

                $version->forceFill([
                    'status' => GenericDefinitionVersion::RELEASED,
                    'schema_hash' => $resolver->genericHash($version),
                    'released_at' => now(),
                ])->save();
            });
        }
    }

    /** @return array<string, array{category: string, name: string, description: string, attributes: list<array<string, mixed>>}> */
    private function definitions(): array
    {
        $brand = ['key' => 'merek', 'label' => 'Merek', 'data_type' => 'string', 'is_searchable' => true, 'overridable' => [...self::LABEL_HELP, 'required']];
        $model = ['key' => 'model', 'label' => 'Model / Tipe', 'data_type' => 'string', 'overridable' => [...self::LABEL_HELP, 'required']];
        $year = ['key' => 'tahun_pembuatan', 'label' => 'Tahun Pembuatan', 'data_type' => 'integer',
            'validation' => ['min' => 1900, 'max' => 2100], 'overridable' => [...self::LABEL_HELP, 'required', 'validation']];

        return [
            'GEN-UMUM' => [
                'category' => 'UMUM',
                'name' => 'Aset Umum',
                'description' => 'Definisi dasar untuk aset yang tidak memiliki definisi khusus.',
                'attributes' => [$brand, $model],
            ],
            'GEN-KENDARAAN-RODA4' => [
                'category' => 'KENDARAAN',
                'name' => 'Kendaraan Roda Empat',
                'description' => 'Mobil penumpang, niaga, dan operasional.',
                'attributes' => [
                    ['key' => 'nomor_polisi', 'label' => 'Nomor Polisi', 'data_type' => 'string', 'is_required' => true, 'is_searchable' => true,
                        'is_controlled' => true, 'validation' => ['max_length' => 12], 'overridable' => self::LABEL_HELP],
                    $brand, $model, $year,
                    ['key' => 'nomor_rangka', 'label' => 'Nomor Rangka', 'data_type' => 'string', 'is_searchable' => true, 'is_controlled' => true,
                        'validation' => ['max_length' => 30], 'overridable' => [...self::LABEL_HELP, 'required']],
                    ['key' => 'nomor_mesin', 'label' => 'Nomor Mesin', 'data_type' => 'string', 'is_controlled' => true,
                        'validation' => ['max_length' => 30], 'overridable' => [...self::LABEL_HELP, 'required']],
                    ['key' => 'jenis_bahan_bakar', 'label' => 'Jenis Bahan Bakar', 'data_type' => 'enum',
                        'options' => [['value' => 'BENSIN', 'label' => 'Bensin'], ['value' => 'SOLAR', 'label' => 'Solar'],
                            ['value' => 'LISTRIK', 'label' => 'Listrik'], ['value' => 'HYBRID', 'label' => 'Hybrid']],
                        'overridable' => [...self::LABEL_HELP, 'required', 'options', 'default_value']],
                    ['key' => 'isi_silinder', 'label' => 'Isi Silinder', 'data_type' => 'integer', 'unit' => 'CC',
                        'validation' => ['min' => 0, 'max' => 20000], 'overridable' => self::LABEL_HELP],
                    ['key' => 'masa_berlaku_stnk', 'label' => 'Masa Berlaku STNK', 'data_type' => 'date', 'overridable' => [...self::LABEL_HELP, 'required']],
                ],
            ],
            'GEN-MESIN-PRODUKSI' => [
                'category' => 'MESIN',
                'name' => 'Mesin Produksi',
                'description' => 'Mesin dan peralatan pada fasilitas produksi.',
                'attributes' => [
                    $brand, $model, $year,
                    ['key' => 'daya', 'label' => 'Daya', 'data_type' => 'decimal', 'unit' => 'KW',
                        'validation' => ['min' => 0, 'decimals' => 2], 'overridable' => [...self::LABEL_HELP, 'required', 'validation']],
                    ['key' => 'jam_operasi', 'label' => 'Jam Operasi Tercatat', 'data_type' => 'integer', 'unit' => 'JAM',
                        'validation' => ['min' => 0], 'overridable' => self::LABEL_HELP],
                ],
            ],
            'GEN-PERABOT-KANTOR' => [
                'category' => 'PERALATAN_KANTOR',
                'name' => 'Perabot Kantor',
                'description' => 'Meja, kursi, lemari, dan perabot sejenis.',
                'attributes' => [
                    $brand,
                    ['key' => 'bahan', 'label' => 'Bahan', 'data_type' => 'string', 'overridable' => [...self::LABEL_HELP, 'required']],
                    ['key' => 'warna', 'label' => 'Warna', 'data_type' => 'string', 'overridable' => [...self::LABEL_HELP, 'required']],
                ],
            ],
            'GEN-ALAT-MEDIS' => [
                'category' => 'PERALATAN_MEDIS',
                'name' => 'Peralatan Medis',
                'description' => 'Alat kesehatan yang memerlukan izin edar dan kalibrasi.',
                'attributes' => [
                    $brand, $model,
                    ['key' => 'nomor_izin_edar', 'label' => 'Nomor Izin Edar', 'data_type' => 'string', 'is_searchable' => true,
                        'validation' => ['max_length' => 50], 'overridable' => [...self::LABEL_HELP, 'required']],
                    ['key' => 'kelas_risiko', 'label' => 'Kelas Risiko', 'data_type' => 'enum',
                        'options' => [['value' => 'A', 'label' => 'Kelas A'], ['value' => 'B', 'label' => 'Kelas B'],
                            ['value' => 'C', 'label' => 'Kelas C'], ['value' => 'D', 'label' => 'Kelas D']],
                        'overridable' => [...self::LABEL_HELP, 'required', 'options']],
                    ['key' => 'jatuh_tempo_kalibrasi', 'label' => 'Jatuh Tempo Kalibrasi', 'data_type' => 'date', 'overridable' => [...self::LABEL_HELP, 'required']],
                ],
            ],
            'GEN-LAPTOP' => [
                'category' => 'PERANGKAT_IT',
                'name' => 'Laptop / Komputer',
                'description' => 'Laptop, desktop, dan workstation.',
                'attributes' => [
                    $brand, $model,
                    ['key' => 'prosesor', 'label' => 'Prosesor', 'data_type' => 'string', 'overridable' => self::LABEL_HELP],
                    ['key' => 'ram', 'label' => 'RAM', 'data_type' => 'integer', 'unit' => 'GB',
                        'validation' => ['min' => 1, 'max' => 4096], 'overridable' => [...self::LABEL_HELP, 'required', 'validation']],
                    ['key' => 'penyimpanan', 'label' => 'Kapasitas Penyimpanan', 'data_type' => 'integer', 'unit' => 'GB',
                        'validation' => ['min' => 1], 'overridable' => [...self::LABEL_HELP, 'required', 'validation']],
                    ['key' => 'sistem_operasi', 'label' => 'Sistem Operasi', 'data_type' => 'enum',
                        'options' => [['value' => 'WINDOWS', 'label' => 'Windows'], ['value' => 'MACOS', 'label' => 'macOS'],
                            ['value' => 'LINUX', 'label' => 'Linux'], ['value' => 'CHROMEOS', 'label' => 'ChromeOS'], ['value' => 'LAINNYA', 'label' => 'Lainnya']],
                        'overridable' => [...self::LABEL_HELP, 'required', 'options', 'default_value']],
                    ['key' => 'garansi_sampai', 'label' => 'Garansi Sampai', 'data_type' => 'date', 'overridable' => [...self::LABEL_HELP, 'required']],
                ],
            ],
            'GEN-TABUNG-GAS' => [
                'category' => 'GAS',
                'name' => 'Tabung Gas',
                'description' => 'Tabung gas industri dan medis bertekanan.',
                'attributes' => [
                    ['key' => 'jenis_gas', 'label' => 'Jenis Gas', 'data_type' => 'enum', 'is_required' => true, 'is_searchable' => true,
                        'options' => [['value' => 'LPG', 'label' => 'LPG'], ['value' => 'OKSIGEN', 'label' => 'Oksigen'],
                            ['value' => 'NITROGEN', 'label' => 'Nitrogen'], ['value' => 'CO2', 'label' => 'Karbon Dioksida'],
                            ['value' => 'ASETILEN', 'label' => 'Asetilen'], ['value' => 'ARGON', 'label' => 'Argon']],
                        'overridable' => [...self::LABEL_HELP, 'options', 'default_value']],
                    ['key' => 'kapasitas', 'label' => 'Kapasitas', 'data_type' => 'decimal', 'unit' => 'KG', 'is_required' => true,
                        'validation' => ['min' => 0, 'max' => 1000, 'decimals' => 2], 'overridable' => [...self::LABEL_HELP, 'validation', 'default_value']],
                    ['key' => 'tekanan_kerja', 'label' => 'Tekanan Kerja', 'data_type' => 'decimal', 'unit' => 'BAR',
                        'validation' => ['min' => 0, 'max' => 500, 'decimals' => 1], 'overridable' => [...self::LABEL_HELP, 'required', 'validation']],
                    ['key' => 'uji_hidrostatik_terakhir', 'label' => 'Uji Hidrostatik Terakhir', 'data_type' => 'date', 'is_controlled' => true,
                        'overridable' => [...self::LABEL_HELP, 'required']],
                    ['key' => 'uji_hidrostatik_berikutnya', 'label' => 'Uji Hidrostatik Berikutnya', 'data_type' => 'date',
                        'overridable' => [...self::LABEL_HELP, 'required']],
                ],
            ],
            'GEN-ALAT-BERAT' => [
                'category' => 'KONSTRUKSI',
                'name' => 'Alat Berat',
                'description' => 'Excavator, crane, loader, dan peralatan konstruksi bermesin.',
                'attributes' => [
                    $brand, $model, $year,
                    ['key' => 'nomor_seri_mesin', 'label' => 'Nomor Seri Mesin', 'data_type' => 'string', 'is_searchable' => true,
                        'overridable' => [...self::LABEL_HELP, 'required']],
                    ['key' => 'jam_operasi', 'label' => 'Hour Meter', 'data_type' => 'integer', 'unit' => 'JAM',
                        'validation' => ['min' => 0], 'overridable' => self::LABEL_HELP],
                ],
            ],
            'GEN-LISENSI-SOFTWARE' => [
                'category' => 'ASET_DIGITAL',
                'name' => 'Lisensi Perangkat Lunak',
                'description' => 'Lisensi software yang memiliki identitas dan masa berlaku.',
                'attributes' => [
                    ['key' => 'vendor', 'label' => 'Vendor', 'data_type' => 'string', 'is_required' => true, 'is_searchable' => true, 'overridable' => self::LABEL_HELP],
                    ['key' => 'jumlah_seat', 'label' => 'Jumlah Seat', 'data_type' => 'integer',
                        'validation' => ['min' => 1], 'overridable' => [...self::LABEL_HELP, 'required', 'validation']],
                    ['key' => 'jenis_lisensi', 'label' => 'Jenis Lisensi', 'data_type' => 'enum',
                        'options' => [['value' => 'PERPETUAL', 'label' => 'Perpetual'], ['value' => 'LANGGANAN', 'label' => 'Langganan'],
                            ['value' => 'OEM', 'label' => 'OEM']],
                        'overridable' => [...self::LABEL_HELP, 'required', 'options']],
                    ['key' => 'berlaku_sampai', 'label' => 'Berlaku Sampai', 'data_type' => 'date', 'overridable' => [...self::LABEL_HELP, 'required']],
                ],
            ],
        ];
    }
}
