# Asset Tracker Enterprise

Platform pengelolaan aset multi-tenant (V1.0). Desain lengkap ada di [docs/](docs/README.md).

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
| 3. Master Data (Generic Master, Tenant Custom Master, cabang/departemen/lokasi) | ⏳ Berikutnya |
| 4–8. Aset, transaksi & workflow, laporan, integrasi frontend, QA | Belum dimulai |

Menu aplikasi hanya menampilkan modul yang sudah berfungsi.

## Menjalankan secara lokal

### 1. Database (sekali saja, sebagai superuser PostgreSQL)

Aplikasi memakai dua role: `ate_owner` (pemilik tabel, khusus migrasi) dan `ate_app` (runtime, tunduk pada RLS).

```sql
CREATE ROLE ate_owner LOGIN PASSWORD '<owner-password>';
CREATE ROLE ate_app   LOGIN PASSWORD '<app-password>';
CREATE DATABASE asset_tracker_v1      OWNER ate_owner;
CREATE DATABASE asset_tracker_v1_test OWNER ate_owner;
-- Lalu, di masing-masing database:
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO ate_owner;
GRANT USAGE ON SCHEMA public TO ate_app;
CREATE EXTENSION IF NOT EXISTS citext;
ALTER DEFAULT PRIVILEGES FOR ROLE ate_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ate_app;
ALTER DEFAULT PRIVILEGES FOR ROLE ate_owner IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO ate_app;
```

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env              # isi DB_PASSWORD (ate_app) dan DB_OWNER_PASSWORD (ate_owner)
php artisan key:generate
php artisan migrate --database=pgsql_owner
php artisan db:seed --database=pgsql_owner                    # data referensi (+ admin platform bila PLATFORM_ADMIN_* diisi)
php artisan db:seed --database=pgsql_owner --class=DemoSeeder # opsional, lokal saja: 2 organisasi demo
php artisan serve
```

`DemoSeeder` membuat `admin@majujaya.test`, `admin@sentosagas.test`, serta `manager@`, `operator@`, `approver@`, `auditor@`, `viewer@` pada kedua domain. Password diambil dari `DEMO_PASSWORD`, atau dibangkitkan acak dan dicetak di konsol. Seeder ini menolak berjalan di production, dan tidak ada akun bawaan dengan password yang diketahui publik.

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

Suite backend menjalankan migrasi lewat koneksi owner, tetapi test dan aplikasi berjalan sebagai `ate_app`. Dengan begitu isolasi tenant diuji di ketiga lapisan: aplikasi, composite foreign key, dan RLS.
