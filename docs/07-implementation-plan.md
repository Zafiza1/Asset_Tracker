# 07 — Rencana Implementasi, Rencana Pengujian, Risiko

## 1. Fase & acceptance criteria

| Fase | Isi | Acceptance criteria (harus terbukti oleh test/perintah) |
|---|---|---|
| **1. Requirement & Architecture** | Dokumen `docs/01–07` | Direview & disetujui; asumsi ⚑ dikonfirmasi |
| **2. Foundation** | Setup monorepo, Laravel + React skeleton, role DB `asset_owner`/`asset_app`, migrasi identitas/org/user/role/permission, login session + CSRF + rate limit, `TenantContext` + global scope fail-closed, RLS, AuditLogger append-only, format error, health check, seeder (platform admin dari env, 2 org demo) | Test: login/logout/lockout rate limit; user tenant selalu terkunci ke organisasinya (parameter `organization_id` dari browser diabaikan); user/organisasi nonaktif langsung kehilangan akses; user platform tidak dapat mengakses endpoint tenant; user org A → 404 untuk resource org B; composite FK menolak relasi lintas org; query tanpa konteks tenant melempar exception; RLS memblokir baca lintas org walau global scope dimatikan; audit UPDATE/DELETE ditolak DB |
| **3. Master Data** | Generic Master (kategori, definisi, versi, atribut, release/deprecate, immutability), unit/jenis lokasi/kondisi/jenis transaksi; tenant: cabang, departemen, lokasi (hierarki), kategori, adopsi definisi, atribut tambahan, override terbatas, release versi + effective schema | Test: versi released tidak dapat diubah (app & trigger); tenant tidak dapat memanggil endpoint generic manage (403) dan tidak dapat mengubah master tenant lain (404); override di luar allowlist ditolak; effective schema deterministik (hash sama) |
| **4. Asset Container & Inventory** | Numbering sequence, transaksi RECEIPT (jalur langsung bila 0 level), container + versi, atribut dinamis, detail/riwayat, edit deskriptif dengan `lock_version`, dokumen (upload/download terotorisasi), archive/restore, migrasi definisi (dry-run, eksekusi, rollback) | Test: registrasi membuat tepat 1 container + versi 1 + atribut tervalidasi; 20 registrasi paralel → nomor unik berurutan tanpa celah duplikat; gagal di tengah → rollback total; rilis generik baru tidak mengubah aset lama; dokumen org B → 404; arsip mempertahankan histori dan nomor |
| **5. Transactions & Workflow** | Draft/submit/approve/reject/return/cancel/post/reverse, handler per jenis, workflow definisi/versi/step/instance, pemilihan workflow, self-approval policy, idempotency | Test: posting ganda → satu efek; dua transaksi untuk aset yang sama → yang kedua ditolak saat submit; approver tak sah/self-approval → 403; approve tenant B → 404; perubahan workflow saat instance berjalan tidak mempengaruhinya; master diarsipkan saat approval → posting gagal bersih; reversal mengembalikan state & histori konsisten |
| **6. Dashboard & Reporting** | Endpoint dashboard, 7 laporan, approval inbox, export job (CSV/XLSX) via queue, import CSV dry-run → RECEIPT batch | Test: angka dashboard = hitungan DB untuk fixture yang diketahui; export org A tidak memuat baris org B; export menghormati data scope; file export hanya dapat diunduh pembuatnya |
| **7. Frontend Integration** | Layout, navigasi berbasis permission, semua halaman §13 terhubung API, form dinamis dari effective schema, state loading/empty/error | `tsc`, lint, build bersih; Vitest untuk komponen kunci (form dinamis, guard permission); Playwright smoke: login → registrasi → transfer → approve → posted → laporan |
| **8. QA & Production Readiness** | Security review (checklist), performance (100k aset seed), deployment rehearsal, backup/restore drill, dokumentasi ops & user | Checklist keamanan terpenuhi; restore dari backup lulus verifikasi jumlah baris + test smoke; dokumentasi README, deployment, backup/recovery, keterbatasan, roadmap |

Setiap fase ditutup dengan: seluruh test hijau, ringkasan perubahan, dan pembaruan dokumen bila desain bergeser.

## 2. Rencana pengujian

| Lapisan | Cakupan | Alat |
|---|---|---|
| Unit | Format & generator nomor, SchemaResolver (merge + override allowlist), AttributeValidator per tipe, graf status, state machine transaksi, ApproverResolver (termasuk self-approval), PermissionResolver/ScopeResolver | PHPUnit |
| Integration (Feature) | Alur penuh per endpoint dengan DB nyata (PostgreSQL, bukan SQLite), verifikasi **isi DB** setelah aksi, rollback saat exception yang disuntikkan | PHPUnit + `RefreshDatabase` |
| Multi-tenant security | 10 skenario wajib §16 sebagai suite `tests/Feature/Security`, plus test otomatis yang mengiterasi **semua route tenant** dan memastikan ID milik tenant lain menghasilkan 404/403 | PHPUnit, koneksi `asset_app` untuk RLS |
| Concurrency | Posting paralel & sequence paralel dengan beberapa proses PHP/koneksi PG | PHPUnit + helper proses |
| Frontend | Komponen & hook kritikal | Vitest + Testing Library |
| E2E smoke | Alur bisnis utama | Playwright |

## 3. Risiko teknis & mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | Kebocoran data lintas tenant karena query lupa scope | Kritis | Global scope fail-closed + composite FK + RLS + test enumerasi semua route |
| R2 | RLS & connection reuse (setting tertinggal antar request/job) | Tinggi | `set_config` di awal request & job, reset di `terminating`; test khusus worker; tanpa persistent connection |
| R3 | Kompleksitas pewarisan & override master | Tinggi | Allowlist properti yang sempit, effective schema dibekukan saat release, unit test SchemaResolver ekstensif |
| R4 | Migrasi definisi aset gagal sebagian | Tinggi | Dry-run wajib, transaksi per aset, laporan per aset, rollback eksplisit, nilai lama tidak dihapus |
| R5 | Race condition transaksi/nomor | Tinggi | Partial unique index, row lock, `lock_version`, idempotency key, test paralel |
| R6 | Performa EAV atribut dinamis | Sedang | Kolom inti di `assets`, nilai bertipe + index komposit, filter atribut dibatasi ke atribut `is_searchable` |
| R7 | Konfigurasi workflow membuat transaksi buntu (tidak ada approver) | Sedang | Validasi saat publish (setiap step harus punya ≥ 1 kandidat approver aktif), peringatan di inbox admin, cancel selalu tersedia |
| R8 | Lockout admin organisasi | Sedang | Invarian ≥ 1 ORG_ADMIN aktif ditegakkan service |
| R9 | Over-scope V1.0 / jadwal | Sedang | Fase berurutan dengan DoD per fase; fitur di luar lingkup hanya di roadmap |
| R10 | Lingkungan dev Windows tanpa Docker | Rendah | Jalankan dengan PHP + PostgreSQL lokal; `docker-compose.dev.yml` tetap disediakan untuk tim lain |
| R11 | Audit log dapat diubah DBA | Rendah | Didokumentasikan; append-only di level app/role DB; hash-chain sebagai kontrol tambahan di roadmap |

## 4. Roadmap pasca V1.0 (bukan bagian implementasi)
MFA/SSO (SAML/OIDC), hash-chain audit, depresiasi, maintenance scheduling, notifikasi email, API token untuk integrasi, Module Management, GPS/RFID/BLE/IoT, integrasi ERP, analitik prediktif.
