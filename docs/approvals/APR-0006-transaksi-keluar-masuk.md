# APR-0006: Keputusan desain Transaksi Keluar & Masuk (F-02/F-03/F-04)

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-17 |
| Diajukan oleh | product-analyst, system-architect, security-engineer |
| Tahap / gate | S2–S7 (ringkas) |
| Area | Produk, arsitektur (skema), security, UX |
| Artefak | `docs/factory/features/F-02-transaksi-keluar-masuk.md` v0.1 |
| Status | **Approved** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-17 |

## Keputusan (masing-masing disetujui)
| ID | Keputusan |
|---|---|
| D-6 | Aset baru dari luar (pembelian, hibah) tetap lewat *Penambahan aset*; transaksi masuk hanya untuk aset yang sebelumnya keluar sementara |
| D-7 | Status aset baru **Di Luar Organisasi** (`off_site`) untuk keluar sementara |
| D-8 | Daftar alasan keluar sesuai §3.1 dan format nomor `OUT-{YYYY}-{SEQ}` / `IN-{YYYY}-{SEQ}` |
| D-9 | Aplikasi mobile tanpa mode offline pada versi ini |
| D-10 | Kontak pihak luar diperlakukan sebagai data pribadi (permission khusus di laporan) |

## Dampak
- Skema: jenis transaksi dan status aset baru, kolom baru (nullable) di `asset_transactions`. Tidak menghapus data.
- Biaya: nol.

## Keputusan
"Setuju." — pemilik produk, 2026-11-17.
