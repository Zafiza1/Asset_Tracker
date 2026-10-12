<?php

namespace Database\Seeders;

use App\Domain\GenericMaster\Models\GenericCondition;
use App\Domain\GenericMaster\Models\GenericTransactionType;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\GenericMaster\Models\Unit;
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

        $units = [
            'KG' => ['Kilogram', 'kg', 'massa'],
            'BAR' => ['Bar', 'bar', 'tekanan'],
            'KW' => ['Kilowatt', 'kW', 'daya'],
            'LITER' => ['Liter', 'L', 'volume'],
            'METER' => ['Meter', 'm', 'panjang'],
            'KM' => ['Kilometer', 'km', 'jarak'],
            'GB' => ['Gigabyte', 'GB', 'kapasitas data'],
            'JAM' => ['Jam', 'jam', 'waktu'],
            'CC' => ['Sentimeter kubik', 'cc', 'volume mesin'],
        ];
        foreach ($units as $code => [$name, $symbol, $quantity]) {
            Unit::query()->firstOrCreate(['code' => $code], ['name' => $name, 'symbol' => $symbol, 'quantity' => $quantity, 'status' => 'active']);
        }

        $conditions = [
            'BAIK' => ['Baik', 1, 'Berfungsi normal.'],
            'RUSAK_RINGAN' => ['Rusak Ringan', 2, 'Masih dapat digunakan dengan keterbatasan.'],
            'RUSAK_BERAT' => ['Rusak Berat', 3, 'Tidak dapat digunakan sampai diperbaiki.'],
            'HILANG' => ['Hilang', 4, 'Tidak ditemukan secara fisik.'],
        ];
        $order = 0;
        foreach ($conditions as $code => [$name, $severity, $description]) {
            GenericCondition::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'severity' => $severity, 'description' => $description, 'sort_order' => ++$order, 'status' => 'active',
            ]);
        }

        // Codes are fixed: each one maps to a transaction handler implemented in code (Phase 5).
        $transactionTypes = [
            'RECEIPT' => ['Penerimaan / Registrasi', 'Mendaftarkan aset baru ke inventory.'],
            'TRANSFER' => ['Perpindahan Lokasi', 'Memindahkan aset ke lokasi lain.'],
            'REASSIGN' => ['Perubahan Penanggung Jawab', 'Mengganti penanggung jawab atau departemen aset.'],
            'STATUS_CHANGE' => ['Perubahan Status/Kondisi', 'Mengubah status operasional atau kondisi aset.'],
            'LOAN' => ['Peminjaman', 'Meminjamkan aset untuk sementara.'],
            'RETURN' => ['Pengembalian', 'Mengembalikan aset yang dipinjam.'],
            'DISPOSAL' => ['Pelepasan', 'Menjual, menghibahkan, memusnahkan, atau menghapus aset hilang.'],
            'CORRECTION' => ['Koreksi Data', 'Memperbaiki data terkontrol yang salah input.'],
            'REVERSAL' => ['Pembalikan', 'Membalik transaksi yang sudah diposting.'],
        ];
        $order = 0;
        foreach ($transactionTypes as $code => [$name, $description]) {
            GenericTransactionType::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'description' => $description, 'sort_order' => ++$order, 'status' => 'active',
            ]);
        }
    }
}
