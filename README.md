# Asset Tracker

Aplikasi pengelolaan aset multi-tenant yang sederhana: pendataan aset, transaksi aset, dan dashboard analisis, semuanya dengan input manual. Arah terkini ada di [docs/08-asset-tracker-sederhana.md](docs/08-asset-tracker-sederhana.md); desain awal di [docs/](docs/README.md).

| Bagian | Teknologi |
|---|---|
| `backend/` | Laravel 13 (PHP 8.4+), API JSON, session cookie Sanctum |
| `frontend/` | React 19 + TypeScript + Vite + Tailwind CSS |
| Database | PostgreSQL 17 (Row-Level Security) |

## Status implementasi

| Fase | Status |
|---|---|
| 1. Requirement & arsitektur | ✅ Selesai (`docs/`) |
| 2. Foundation: auth, tenancy, RLS, RBAC, audit, provisioning organisasi, manajemen user/role | ✅ Selesai |
| 3. Master Data: Generic Master + versioning, Tenant Custom Master (adopsi, atribut tambahan, override, effective schema), departemen/lokasi (cabang dihapus 2026-10-12), kategori (kondisi aset dihapus 2026-10-19) | ✅ Selesai ([catatan](docs/07-implementation-plan.md#4-catatan-penyelesaian-fase), [API](docs/api/master-data.md)) |
| 4. Pendataan Aset, Transaksi Aset, Dashboard Analisis (versi sederhana, tanpa workflow approval) | ✅ Selesai ([rincian](docs/08-asset-tracker-sederhana.md)) |

Menu aplikasi hanya menampilkan modul yang sudah berfungsi. Data internal (Generic Data) tidak menjadi menu user; developer menelusurinya dengan `php artisan asset:inspect`. Satu modul dapat dimatikan sementara untuk maintenance dengan `MODULES_DISABLED` tanpa memengaruhi modul lain.

## Menjalankan secara lokal

### 1. Database (sekali saja, sebagai superuser PostgreSQL)

Butuh PostgreSQL 16 atau lebih baru. Aplikasi memakai lima role (SEC-07, `docs/asset-tracker-audit.md`):

| Role | Login | Untuk |
|---|---|---|
| `ate_owner` | ya | pemilik tabel; hanya migrasi, seeder, dan `db:context-key` |
| `ate_app` | ya | runtime; tunduk pada RLS dan hanya menerima konteks bertanda tangan (`app.context`) |
| `ate_session` | ya | koneksi `pgsql_session`: tabel `sessions`, `cache`, `cache_locks` |
| `ate_queue` | ya | koneksi `pgsql_queue`: tabel `jobs`, `job_batches`, `failed_jobs` |
| `ate_ctx` | tidak | pemilik fungsi verifikasi konteks dan kuncinya (skema `ate_ctx`, `ate_private`) |

```sql
CREATE ROLE ate_owner   LOGIN PASSWORD '<owner-password>';
CREATE ROLE ate_app     LOGIN PASSWORD '<app-password>';
CREATE ROLE ate_session LOGIN PASSWORD '<session-password>';
CREATE ROLE ate_queue   LOGIN PASSWORD '<queue-password>';
CREATE ROLE ate_ctx NOLOGIN BYPASSRLS;        -- membaca keanggotaan untuk verifikasi konteks
GRANT pg_read_all_stats TO ate_ctx;           -- backend_start koneksi lain (pengikatan klaim)
GRANT ate_ctx TO ate_owner WITH INHERIT FALSE, SET TRUE;  -- migrasi membuat objek sebagai ate_ctx
CREATE DATABASE asset_tracker_v1      OWNER ate_owner;
CREATE DATABASE asset_tracker_v1_test OWNER ate_owner;
-- Lalu, di masing-masing database:
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO ate_owner;
GRANT USAGE ON SCHEMA public TO ate_app;
CREATE EXTENSION IF NOT EXISTS citext;
ALTER DEFAULT PRIVILEGES FOR ROLE ate_owner IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO ate_app;
```

Hak tabel diatur oleh migrasi. Sejak migrasi `001400`, tabel baru **tidak** otomatis bisa diakses `ate_app`: migrasinya harus memberi GRANT sendiri, dan tabel tanpa RLS harus didaftarkan di `tests/Feature/Security/DatabasePrivilegesTest.php`.

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env              # isi password ate_app, ate_owner, ate_session, ate_queue
php artisan key:generate
# Kunci konteks DB: "base64:" + 32 byte acak, beda per lingkungan, simpan di secret store.
php -r 'echo "DB_CONTEXT_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;' >> .env
php artisan migrate --database=pgsql_owner
php artisan db:context-key install                            # setelah setiap migrate:fresh juga
php artisan db:seed --database=pgsql_owner                    # data referensi (+ admin platform bila PLATFORM_ADMIN_* diisi)
php artisan db:seed --database=pgsql_owner --class=DemoSeeder # opsional, lokal saja: 2 organisasi demo
php artisan serve
```

`DemoSeeder` membuat `admin@majujaya.test`, `admin@sentosagas.test`, serta `supervisor@` pada kedua domain, ditambah `pemilik@grup.test` yang menjadi Admin di kedua organisasi (satu login, pindah organisasi lewat menu di header). Password diambil dari `DEMO_PASSWORD`, atau dibangkitkan acak dan dicetak di konsol. Seeder ini menolak berjalan di production, dan tidak ada akun bawaan dengan password yang diketahui publik.

### 3. Frontend

```bash
cd frontend
npm install
npm run dev        # http://localhost:5173 — /api dan /sanctum di-proxy ke http://localhost:8000
```

## Pengujian

```bash
cd backend && php artisan test         # buat .env.testing (salinan .env dengan DB_DATABASE=asset_tracker_v1_test)
cd frontend && npm run typecheck && npm run lint && npm test && npm run build
```

Suite backend menjalankan migrasi lewat koneksi owner, tetapi test dan aplikasi berjalan sebagai `ate_app`. Dengan begitu isolasi tenant diuji di ketiga lapisan: aplikasi, composite foreign key, dan RLS. `.env.testing` juga butuh `DB_CONTEXT_KEY` serta password `ate_session`/`ate_queue`; suite memasang kunci sendiri setelah `migrate:fresh`. Setiap request di test dimulai tanpa konteks, sama seperti di produksi.

Role adalah objek tingkat cluster: uji perubahan role atau hak akses di cluster PostgreSQL terpisah, bukan di server yang juga dipakai database development.
