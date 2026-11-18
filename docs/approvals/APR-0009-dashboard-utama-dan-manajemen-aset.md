# APR-0009: Dashboard Utama per Role dan Dashboard Manajemen Aset (F-06)

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-18 |
| Diajukan oleh | product-analyst, system-architect, ui-ux-designer |
| Tahap / gate | S4 |
| Area | User experience, data |
| Artefak | `docs/factory/features/F-06-dashboard-utama-dan-manajemen-aset.md` v0.1 |
| Status | **Approved** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-18 |

## Keputusan (D-18..D-24)
| ID | Keputusan |
|---|---|
| D-18 | Isi Beranda ditentukan oleh izin pengguna; tabel F-06 §3.1 adalah hasil untuk tiga role bawaan |
| D-19 | Kartu Beranda hanya dapat diurutkan dan disembunyikan; widget analisis ada di Dashboard Aset |
| D-20 | Beranda diperbarui tiap 60 detik saat tab aktif, tanpa websocket |
| D-21 | Tiga template Dashboard Aset bawaan dipetakan ke role; Super Admin dapat mengubah template dan pemetaannya; perubahan pengguna bersifat pribadi |
| D-22 | Template dashboard kategori diwarisi subkategori; lima template contoh kategori disediakan |
| D-23 | Frontend F-01b/F-01c digantikan task F-06; backend F-01 dipakai ulang |
| D-24 | Penyusutan/nilai buku tidak termasuk; widget memakai nilai perolehan |

## Syarat
Mockup F-06 disetujui pemilik produk sebelum UI dikodekan ([APR-0008](APR-0008-mockup-sebelum-figma.md)).

## Keputusan
"Setuju." (pemilik produk, 2026-11-18)
