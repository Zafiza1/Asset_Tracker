# T-01: Verifikasi Dashboard Kustom + Report Designer (WIP)

| Field | Value |
|---|---|
| Tanggal | 2026-11-16 |
| Basis | `c9d3256` + working tree (belum di-commit) |
| Pemverifikasi | qa-engineer (test), security-engineer (review), bukan pembuat fitur |
| Status | **Lulus untuk commit**, dengan 2 kegagalan test lama yang ditangani terpisah (T-17) |

## 1. Test

| Pemeriksaan | Perintah | Hasil |
|---|---|---|
| Backend, suite penuh | `php -d memory_limit=1G vendor/bin/phpunit` (DB `asset_tracker_v1_test`) | 449 test, **447 lulus**, 2 gagal, 1 risky · 4446 assertion · 214 s |
| Test WIP | `DashboardCoreTest`, `DashboardFoundationTest`, `ReportDesignerBindingTest`, `ReportDesignerFoundationTest`, `RouteSecurityTest` | Semua lulus |
| Format PHP (file WIP) | `vendor/bin/pint --test <file WIP>` | Lulus |
| Frontend typecheck | `npm run typecheck` | Lulus (setelah T-02) |
| Frontend unit | `vitest run` | 31 file, 203 test lulus |
| Frontend lint | `npm run lint` | Lulus; hanya warning di file lama |
| Frontend build | `npm run build` | Lulus; peringatan chunk > 500 kB |

### Kegagalan (bukan dari WIP; file test tidak disentuh WIP)
1. `RegistrationTest::test_sign_up_creates_an_organization_with_the_registrant_as_admin_and_logs_in` (baris 45): `orderBy('code')` mengikuti collation database. Collation lokal mengabaikan `_`, sehingga `SUPER_ADMIN` < `SUPERVISOR`, sedangkan test mengharapkan urutan byte. Test bergantung pada lingkungan.
2. `InvitationTest::test_scope_limited_inviter_cannot_grant_access_outside_their_scope` (baris 153): pengundang ber-role `SUPERVISOR`. Sejak `468f237` (2026-11-05, `docs/08 §9`) hanya Super Admin yang dapat mengundang, sehingga permintaan ditolak `FORBIDDEN` sebelum pemeriksaan scope. Test belum mengikuti aturan bisnis yang berlaku, sehingga perlindungan scope pada undangan saat ini **tidak teruji**.

### Risky
`DatabasePrivilegesTest::test_new_tables_get_no_privileges_by_default` lulus dengan 0 assertion saat `pg_default_acl` tidak punya baris tabel. Kondisi itu memang aman, tetapi test tidak mencatatnya sebagai assertion.

### Catatan lingkungan
`php artisan test` berhenti dengan "Premature end of PHP process" di `SpreadsheetEditTest`. Penyebabnya: `memory_limit` PHP lokal 128M, dan `-d memory_limit` tidak diteruskan ke proses PHPUnit yang dijalankan artisan. Kelas yang sama lulus sendiri (13/13), dan suite penuh berjalan tuntas lewat `vendor/bin/phpunit` dengan 1G. CI (T-04) harus memakai `memory_limit` ≥ 512M.

## 2. Review Security

Tidak ada temuan Critical, High, atau Medium.

| Kontrol | Bukti |
|---|---|
| Data laporan/pratinjau mengikuti data scope | `ReportDataResolver::assetQuery` memakai `AssetReport::query` (menerapkan `AssetScope`); `transactionQuery` membatasi `asset_id` ke aset dalam scope |
| Field berizin disembunyikan, fail-closed | `ReportDataResolver::rowDisplay` baris 109–115 |
| Permission route | `report.update` (validate), `report.preview` (preview, permission baru), `dashboard.view` / `dashboard.manage` |
| Otorisasi per dashboard | `Gate::authorize` (create/manage/delete) dan `visible()` → 404 untuk dashboard yang tidak boleh dilihat |
| Isolasi lintas tenant | `Dashboard` dan `DashboardTemplate` masuk `RouteSecurityTest::foreignIds` |
| Migrasi `2026_11_16_000600` | Hanya menambah permission ke template Super Admin/Supervisor; tidak membuat tabel (tidak perlu GRANT/RLS) |

### Temuan Low (tidak memblokir)
| ID | Temuan | Rekomendasi |
|---|---|---|
| L-1 | GET `/dashboards`, `/dashboards/overview`, `/default`, `/categories/{id}` dapat membuat baris dashboard (efek tulis pada GET) | Buat dashboard overview/kategori saat organisasi atau kategori dibuat, atau lewat POST |
| L-2 | `GET /dashboards/navigation` menampilkan semua kategori aktif kepada pengguna yang hanya punya `dashboard.view` | Terima bila nama kategori dianggap tidak sensitif; jika tidak, tampilkan kategori yang memiliki aset dalam scope |
| L-3 | Belum ada test khusus bahwa pratinjau laporan pengguna ber-scope lokasi tidak memuat aset di luar scope | Tambahkan test di `ReportDesignerBindingTest` |
