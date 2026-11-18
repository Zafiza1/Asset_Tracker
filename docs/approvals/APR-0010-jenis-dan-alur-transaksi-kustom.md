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

## Keputusan Tambahan (2026-11-18)
| ID | Keputusan |
|---|---|
| D-34 | Semua editor (desainer laporan, atur tata letak/tambah widget dashboard, template dashboard, jenis/field/alur transaksi) hanya di web pada layar ≥ 768px. Di ponsel/layar sempit hanya tampil hasil jadinya (dilihat dan dipakai), tanpa tombol ubah |

"Untuk versi mobile ndak usah ada editor, edit cukup di web aja; di mobile yang udah jadinya." (pemilik produk, 2026-11-18)

## Mockup
[F-07-jenis-dan-alur-transaksi.html](../design/mockups/F-07-jenis-dan-alur-transaksi.html) disetujui pemilik produk 2026-11-18 ("Setuju"), termasuk tampilan ponsel tanpa editor (D-34). Syarat APR-0008 terpenuhi; UI boleh dikodekan.

## Syarat
Mockup F-07 disetujui pemilik produk sebelum UI dikodekan ([APR-0008](APR-0008-mockup-sebelum-figma.md)). Migrasi jenis bawaan (F-07f) tidak boleh mengubah perilaku: seluruh test transaksi yang ada tetap lulus.

## Keputusan
"Setuju." (pemilik produk, 2026-11-18)
