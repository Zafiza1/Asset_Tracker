# Project Brief: Asset Tracker

| Field | Value |
|---|---|
| Tahap | 1 — Discovery (disusun ulang dari kondisi yang berjalan) |
| Versi | 0.1 |
| Penyusun | product-analyst |
| Status | **Approved** ([APR-0002](../approvals/APR-0002-project-brief.md)); asumsi ⚑ diterima sampai dikoreksi |
| Sumber | `README.md`, `docs/01`, `docs/08`, `docs/asset-tracker-audit.md`, kode per `c9d3256` |

Bagian bertanda ⚑ disimpulkan dari kode/dokumen dan perlu dikonfirmasi.

## 1. Masalah
Organisasi yang memiliki banyak aset fisik (peralatan, kendaraan, perangkat TI) mendata aset dengan spreadsheet atau catatan terpisah. Akibatnya lokasi, status, dan peminjam aset tidak terlacak, riwayat perpindahan hilang, dan tidak ada kontrol siapa boleh mengubah apa. Pemilik yang mengelola **beberapa perusahaan** harus memakai sistem terpisah untuk setiap perusahaan.

## 2. Tujuan & Ukuran Keberhasilan
| Tujuan | Ukuran | Target ⚑ |
|---|---|---|
| Semua aset tercatat dengan lokasi dan status yang benar | `asset:inspect --verify-all` tanpa selisih | 0 selisih |
| Setiap perubahan aset tertelusur | Perubahan yang tercatat di transaksi/audit | 100% |
| Data antarperusahaan tidak pernah bocor | Test isolasi lintas tenant (semua route) | Selalu hijau |
| Pengguna non-teknis dapat bekerja tanpa pelatihan panjang | Waktu mendaftarkan satu aset | < 2 menit ⚑ |
| Respons cepat pada data besar | Daftar aset p95 | < 500 ms untuk 100k aset/tenant |

## 3. Target Pengguna
| Persona | Kebutuhan | Peran di sistem |
|---|---|---|
| Pemilik / pengelola grup perusahaan | Satu login untuk beberapa organisasi; ringkasan aset | Super Admin |
| Supervisor | Menyetujui perubahan aset dan master data; mengatur role dan scope anggota | Supervisor (`approval.manage`) |
| Staf pengelola aset | Mendata aset, mencatat pemindahan, peminjaman, perubahan status | Admin / role kustom, dibatasi data scope lokasi |
| Petugas lapangan | Memindai barcode aset untuk melihat detailnya | Aplikasi mobile (hanya baca) |
| Operator platform | Mengelola lisensi dan organisasi tanpa melihat data bisnis tenant | User platform |

## 4. Solusi (garis besar)
Aplikasi web SaaS multi-tenant untuk pendataan aset, transaksi aset (pemindahan, perubahan status, peminjaman, pengembalian, catatan), approval satu tingkat untuk perubahan oleh non-approver, dashboard analisis, master data bertingkat (kategori dengan atribut tambahan, lokasi, departemen), undangan pengguna dengan kode lisensi, dan aplikasi mobile pemindai barcode. Semua data diinput manual.

## 5. Lingkup
- **Sudah berjalan:** lihat `README.md` status implementasi dan `docs/08`.
- **Sedang dikerjakan:** dashboard kustom (widget, template) dan Report Designer (desain laporan, binding data, pratinjau).
- **Berikutnya (audit P1–P6):** onboarding tanpa organisasi, lupa password, penyederhanaan permission, satuan/aturan atribut, riwayat perubahan aset, transaksi multi-aset, reversal, ekspor laporan, E2E, CI.
- **Di luar lingkup:** GPS/RFID/BLE/IoT, integrasi ERP, depresiasi akuntansi, analitik prediktif, marketplace modul.

## 6. Batasan
- Biaya: **nol** untuk tools tambahan; semua self-hosted atau free tier.
- Teknologi yang sudah ditetapkan: Laravel 13 / PHP 8.4+, React 19 + TypeScript, PostgreSQL 16+ dengan RLS, Expo.
- Bahasa UI Indonesia; kode dan permission berbahasa Inggris.
- Pengembangan di Windows tanpa Docker (risiko R10 di `docs/07`).

## 7. Asumsi & Risiko
| Item | Jenis | Dampak | Validasi / mitigasi |
|---|---|---|---|
| Pengguna utama adalah UKM/grup perusahaan di Indonesia ⚑ | Asumsi | Prioritas fitur dan bahasa | Konfirmasi pemilik produk |
| Belum ada pengguna produksi ⚑ | Asumsi | Menentukan seberapa mendesak SEC-05/06 dan prosedur migrasi data | Konfirmasi |
| Model bisnis berbasis lisensi per organisasi ⚑ | Asumsi | Modul lisensi dan kode undangan | Konfirmasi |
| Dokumen desain usang dipakai sebagai acuan | Risiko | Keputusan salah | Sprint 1 T-07..T-09 |
| Pekerjaan besar belum di-commit | Risiko | Kehilangan kerja | Sprint 1 T-01 |

## 8. Pertanyaan Terbuka
1. Siapa pengguna produksi pertama, dan kapan targetnya?
2. Apakah approval bertingkat (G-20) masih diinginkan, atau approval satu tingkat sudah cukup?
3. Apakah ekspor laporan (CSV/XLSX) dan Report Designer adalah satu fitur yang sama?

## Change Log
| Versi | Tanggal | Perubahan | Oleh |
|---|---|---|---|
| 0.1 | 2026-11-16 | Disusun dari kondisi yang berjalan saat onboarding | product-analyst |
