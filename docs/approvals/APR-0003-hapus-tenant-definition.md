# APR-0003: Hapus API dan tabel Tenant Definition lama

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-16 |
| Diajukan oleh | system-architect (onboarding D-2; `docs/08 §7` butir 1; audit G-15) |
| Tahap / gate | S5 / S6 |
| Area | Arsitektur (perubahan skema tidak dapat dibalik untuk data lama) |
| Status | **Approved with conditions** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-16 |

## Keputusan yang Diminta
Menghapus API *Tenant Definition* (`asset-definitions`: definisi, versi, atribut, override tenant) beserta tabelnya, karena sudah digantikan atribut kategori (`docs/08 §4`) dan tidak dipakai UI.

## Opsi
| Opsi | Biaya | Risiko | Dapat dibalik |
|---|---|---|---|
| **A. Hapus API + tabel (dipilih)** | 0 | Data definisi lama hilang | Tidak, kecuali dari backup |
| B. Bekukan (API read-only, tabel dipertahankan) | 0 | Kode mati tetap dipelihara | Ya |

## Syarat
1. Dikerjakan sebagai task terpisah **setelah Sprint 1** (backlog P3, T-20), tidak di tengah stabilisasi.
2. database-engineer terlebih dahulu memastikan tidak ada aset, master, atau laporan yang mereferensikan tabel tersebut, termasuk Report Designer yang sedang dikerjakan.
3. Migrasi penghapusan didahului backup dan ekspor isi tabel ke file arsip; rencana rollback = restore dari backup.
4. Route, controller, permission (`master.tenant.migrate`), dan test terkait dihapus dalam PR yang sama; `RouteSecurityTest` tetap hijau.
5. Generic Master milik platform **tidak** termasuk dalam penghapusan ini.

## Keputusan
"D-1 sampai D-5 saya setuju." — pemilik produk, 2026-11-16.
