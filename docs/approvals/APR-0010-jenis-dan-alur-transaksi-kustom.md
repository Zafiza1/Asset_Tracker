# APR-0010: Jenis dan Alur Transaksi Buatan Organisasi (F-07)

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-18 |
| Diajukan oleh | product-analyst, system-architect, database-engineer, security-engineer |
| Tahap / gate | S4 |
| Area | Data, security, user experience, dependency |
| Artefak | `docs/factory/features/F-07-jenis-dan-alur-transaksi-kustom.md` v0.1 |
| Status | **Approved** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-18 |

## Keputusan (D-25..D-33)
| ID | Keputusan |
|---|---|
| D-25 | Editor alur memakai `@xyflow/react` (React Flow, lisensi MIT, gratis) sebagai dependency frontend baru |
| D-26 | Tujuh jenis transaksi yang ada menjadi jenis sistem dengan alur bawaan; organisasi boleh menambah field dan persetujuan, aturan inti tetap |
| D-27 | Membuat jenis dan alur hanya untuk Super Admin dan Supervisor (izin `transaction_type.manage`, `transaction_flow.manage`) |
| D-28 | Transaksi berjalan memakai versi alur saat dibuat |
| D-29 | Aset dikunci selama transaksinya berjalan, kecuali jenis yang ditandai tidak mengunci |
| D-30 | Persetujuan di alur memakai tugas langkah (`transaction_tasks`), bukan replay `ApprovalRequest`; menu Persetujuan menampilkan keduanya |
| D-31 | Field kustom tersedia di Report Designer dan widget dashboard |
| D-32 | Fase pertama tanpa mobile |
| D-33 | Bertahap: jenis + field, lalu mesin dan editor alur, lalu migrasi jenis bawaan |

## Syarat
Mockup F-07 disetujui pemilik produk sebelum UI dikodekan ([APR-0008](APR-0008-mockup-sebelum-figma.md)). Migrasi jenis bawaan (F-07f) tidak boleh mengubah perilaku: seluruh test transaksi yang ada tetap lulus.

## Keputusan
"Setuju." (pemilik produk, 2026-11-18)
