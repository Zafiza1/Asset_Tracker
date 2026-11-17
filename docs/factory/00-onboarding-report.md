# 00 — Onboarding ke AI Software Factory

| | |
|---|---|
| Tanggal | 2026-11-16 |
| Commit dasar | `c9d3256` (`main`, 1 commit belum di-push ke `origin/main`) |
| Working tree | **Tidak bersih**: fitur Dashboard (kustom) dan Report Designer sedang dikerjakan, belum di-commit |
| Penyusun | project-manager + product-analyst + system-architect + security-engineer (peran AI Software Factory, `D:\Workspace`) |
| Sifat | Baca-saja terhadap kode. Hanya menambah dokumen di `docs/factory/`. Tidak ada kode, dependency, atau database yang diubah. |

## 1. Ringkasan

Asset Tracker adalah project **brownfield yang matang**: isolasi tenant tiga lapis (global scope, composite FK, RLS dengan konteks bertanda tangan), RBAC dengan anti-eskalasi, transaksi append-only, approval satu tingkat, undangan pengguna, lisensi, dan aplikasi mobile pemindai barcode. Dokumentasinya juga lebih lengkap dari kebanyakan project.

Karena itu onboarding **tidak menulis ulang** dokumen yang ada. Onboarding memetakan dokumen ke 10 tahap pipeline, menandai yang usang atau belum ada, lalu menyusun backlog berikutnya dengan format factory.

Masalah utama yang ditemukan:
1. **Dokumen desain sudah bergeser dari kode.** `docs/08` menggantikan sebagian 01–07, tetapi ERD (03), lifecycle/workflow (06), dan arsitektur (02) belum diperbarui. Agent yang membaca 03 atau 06 akan mendapat gambaran yang salah.
2. **Belum ada CI, test E2E, maupun security scanner otomatis.** Kualitas saat ini bergantung pada disiplin manual.
3. **Pekerjaan besar yang belum di-commit** (Dashboard + Report Designer: service, controller, policy, migrasi, test) dan 1 commit yang belum di-push.
4. **Status temuan security di `asset-tracker-audit.md` (2026-10-24) sudah tidak mutakhir.** Sebagian sudah diperbaiki di kode, sebagian masih terbuka. Lihat [07-security-status.md](07-security-status.md).

## 2. Bukti yang dijalankan saat onboarding

| Pemeriksaan | Hasil |
|---|---|
| `git status` | 9 file diubah + 16 path baru (Dashboard, ReportDesigner, Policies, migrasi `2026_11_16_000600`, test) |
| `git rev-list origin/main..main` | 1 commit belum di-push |
| `npm run typecheck` (frontend) | **Gagal**: `src/test/usersPage.test.tsx(2,46): TS6133 'within' is declared but never read` |
| `vitest run` (frontend) | **31 file, 203 test lulus** |
| Test backend (`php artisan test`) | **Tidak dijalankan**: suite menjalankan `migrate:fresh` pada database lokal; dijalankan oleh pemilik atau di CI |
| Jumlah metode test backend | 422 (hitungan statis) |
| `.github/` | Tidak ada |
| Playwright / E2E | Tidak ada |

## 3. Pemetaan ke 10 Tahap Pipeline

Status: ✅ lengkap dan mutakhir · ⚠️ ada tetapi sebagian usang/tidak lengkap · ❌ belum ada

| # | Tahap | Dokumen yang ada | Status | Tindakan |
|---|---|---|---|---|
| 1 | Discovery & Brainstorming | Sebagian di `01 §1`, `README` | ❌ | Dibuat: [01-project-brief.md](01-project-brief.md) (draft, perlu approval) |
| 2 | Requirement Analysis | `01-requirements.md` (draft 0.1) + `08` (arah terkini) | ⚠️ | Gabungkan 01 + 08 menjadi satu PRD mutakhir dengan ID requirement dan acceptance criteria (T-06) |
| 3 | System Flow Design | `06-lifecycle-and-workflow.md` (workflow approval bertingkat yang **sudah dihapus**) | ⚠️ | Dibuat: [03-system-flow.md](03-system-flow.md) untuk alur yang berlaku; tandai 06 sebagai usang (T-07) |
| 4 | UI/UX Design | `09-ui-design-system.md`, test design system | ⚠️ | **Figma disetujui** ([APR-0001](../approvals/APR-0001-figma-sebagai-sumber-desain.md)): token dan panduan di [docs/design/](../design/figma-guide.md); file Figma dibangun di T-14..T-16 |
| 5 | System Architecture | `02-architecture.md`; keputusan tersebar di 07 §4 dan 08 | ⚠️ | Catat keputusan yang sudah diambil sebagai ADR retroaktif (T-08) |
| 6 | Database Engineering | `03-database-erd.md`, `04-data-ownership-matrix.md` | ⚠️ | ERD memuat container/definisi/cabang/kondisi yang sudah dihapus; ±30 migrasi setelahnya belum tercermin. Regenerasi dari migrasi (T-09) |
| 7 | Security Planning | `05-rbac-matrix.md`, `asset-tracker-audit.md` | ⚠️ | Dibuat: [07-security-status.md](07-security-status.md) dengan status temuan terkini dan target ASVS L2 |
| 8 | Implementation Planning | `07` (fase 1–8), audit §7 (P0–P6) | ⚠️ | Dibuat: [08-sprint-backlog.md](08-sprint-backlog.md), Sprint 1 dengan agent, tier, dan verifier |
| 9 | Development & Testing | 422 test backend, 203 test frontend | ⚠️ | Kuat di unit/feature; belum ada CI, E2E, dan test konkurensi |
| 10 | Independent Audit & Release | Audit 2026-10-24 | ⚠️ | Belum ada checklist deployment, pipeline CI, maupun audit ulang setelah perubahan besar sejak 24 Oktober |

## 4. Temuan Onboarding

| ID | Temuan | Dampak | Ditangani di |
|---|---|---|---|
| O-1 | Pekerjaan Dashboard + Report Designer belum di-commit, termasuk migrasi permission baru | Risiko hilang; belum melalui verifikasi independen | T-01 |
| O-2 | 1 commit di `main` belum di-push | Tidak ada cadangan di remote | T-01 |
| O-3 | Typecheck frontend gagal (1 error) | Build/CI akan merah | T-02 (diperbaiki, in review) |
| O-4 | `backend/AGENTS.md` berisi perintah unduh-dan-jalankan skrip remote (`php.new`) dan `composer require laravel/boost` (SEC-10) | Risiko rantai pasok bagi agent AI yang membaca file ini | T-03 |
| O-5 | Tidak ada CI maupun security scanner otomatis | Regresi dan celah tidak tertangkap otomatis | T-04 |
| O-6 | Dokumen 02, 03, 06 usang terhadap kode | Agent dan developer mengambil keputusan dari desain yang salah | T-07, T-08, T-09 |
| O-7 | `backend/add_reference_column.sql` ter-commit di luar folder migrasi | Perubahan skema di luar jalur migrasi, rawan diterapkan manual | T-10 |
| O-8 | `.env.example` memakai `APP_DEBUG=true` dan `SESSION_SECURE_COOKIE=false`, tanpa checklist produksi | Konfigurasi lokal terbawa ke produksi | T-05 |

## 5. Keputusan (semua sudah diputuskan 2026-11-16)

| ID | Keputusan | Area | Rekomendasi |
|---|---|---|---|
| D-1 | Setujui Project Brief (Tahap 1) | Produk | **Disetujui 2026-11-16** ([APR-0002](../approvals/APR-0002-project-brief.md)) |
| D-2 | Hapus API dan tabel *Tenant Definition* lama (`docs/08 §7` butir 1, G-15) | Arsitektur | **Disetujui dengan syarat 2026-11-16** ([APR-0003](../approvals/APR-0003-hapus-tenant-definition.md)); dikerjakan setelah Sprint 1 (T-20) |
| D-3 | Sumber kebenaran UI: Figma atau design system di kode | UX | **Diputuskan 2026-11-16: pakai Figma** ([APR-0001](../approvals/APR-0001-figma-sebagai-sumber-desain.md)) |
| D-4 | Urutan prioritas: stabilisasi (Sprint 1) sebelum fitur baru P1–P6 | Produk | **Disetujui 2026-11-16** ([APR-0004](../approvals/APR-0004-sprint-1-stabilisasi.md)) |
| D-5 | Tutup SEC-05/06 (lupa password mandiri, limiter per akun) sebelum produksi | Security | **Disetujui 2026-11-16** ([APR-0005](../approvals/APR-0005-sec-05-06-sebelum-produksi.md)); blocker rilis, Sprint 2 |

## 6. Cara Bekerja Mulai Sekarang

1. Semua pekerjaan baru masuk lewat [08-sprint-backlog.md](08-sprint-backlog.md) (atau Linear), dengan agent pemilik, skill, lock scope, tier model, dan verifier.
2. Fitur baru melewati tahap 1–8 secara **ringkas**: satu bagian di PRD, alur di 03, ADR bila menyangkut arsitektur, dan pembaruan security status. Tidak perlu dokumen penuh untuk setiap fitur.
3. Pembangun fitur tidak menyatakan fiturnya sendiri benar. Urutan verifikasinya: CI → QA → security → auditor → project-manager.
4. Mode tanpa biaya: semua tools memakai free tier atau self-hosted (lihat `D:\Workspace\documentation\tool-stack.md`).
