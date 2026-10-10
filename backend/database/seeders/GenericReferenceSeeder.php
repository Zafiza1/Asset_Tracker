<?php

namespace Database\Seeders;

use App\Domain\GenericMaster\Models\LocationType;
use Illuminate\Database\Seeder;

/** Platform-owned generic reference data needed by every organization. Idempotent. */
class GenericReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'KANTOR' => 'Kantor',
            'GUDANG' => 'Gudang',
            'PABRIK' => 'Pabrik / Fasilitas Produksi',
            'LAPANGAN' => 'Lokasi Lapangan / Proyek',
            'RUANGAN' => 'Ruangan',
            'KENDARAAN' => 'Pool Kendaraan',
            'DATA_CENTER' => 'Data Center / Ruang Server',
            'LAINNYA' => 'Lainnya',
        ];

        foreach ($types as $code => $name) {
            LocationType::query()->updateOrCreate(['code' => $code], ['name' => $name, 'status' => 'active']);
        }
    }
}
