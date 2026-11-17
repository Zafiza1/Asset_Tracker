# Security Status: Asset Tracker

| Field | Value |
|---|---|
| Tahap | 7 — Security Planning (pembaruan status) |
| Tanggal | 2026-11-16 |
| Penyusun | security-engineer |
| Target | OWASP ASVS 5.0 **Level 2** (sesuai NFR `docs/01 §4`) |
| Fitur AI di produk | Tidak ada. OWASP AISVS berlaku untuk agent yang mengembangkan project ini (lihat SEC-10) |
| Sumber | `docs/asset-tracker-audit.md` (2026-10-24), kode per `c9d3256` |

Status: **Terbuka** · **Kemungkinan selesai** (ada bukti di kode, belum diverifikasi ulang oleh auditor) · **Selesai** (terverifikasi)

## 1. Status Temuan Audit

| ID | Temuan | Risiko | Status | Bukti saat onboarding | Tindakan |
|---|---|---|---|---|---|
| SEC-01 | Penambahan akun tanpa persetujuan, email tidak diverifikasi | High | Kemungkinan selesai | Migrasi `001100_create_organization_invitations`, `InvitationTest`, `InvitationAcceptanceTest`, `docs/08 §9` | Auditor memverifikasi bahwa tidak ada lagi jalur penempelan akun langsung (T-11) |
| SEC-02 | Enumerasi email lintas platform | Medium | Perlu dicek | Belum ada bukti | Termasuk dalam verifikasi T-11 |
| SEC-03 | Eskalasi scope via `PUT /users/{id}/scopes` | Medium | Kemungkinan selesai | `DataScopeEscalationTest`; perbaikan 2026-10-26 sudah di `main` | T-11 |
| SEC-04 | Lolos dari scope lokasi dengan memindah induk | Medium | Kemungkinan selesai | `DataScopeEscalationTest` | T-11 |
| SEC-05 | Proteksi brute-force terbatas | Low | Terbuka · **blocker rilis** ([APR-0005](../approvals/APR-0005-sec-05-06-sebelum-produksi.md)) | Hanya `throttle:login` per route; limiter per akun belum terlihat | T-22 |
| SEC-06 | Tidak ada pemulihan akun mandiri | Medium | Terbuka · **blocker rilis** ([APR-0005](../approvals/APR-0005-sec-05-06-sebelum-produksi.md)) | Tidak ada route lupa password; yang ada hanya reset oleh admin | T-21 |
| SEC-07 | RLS bergantung pada GUC yang dapat diubah | — | Kemungkinan selesai | Migrasi `001300_signed_database_context`, `001400`, `001500`; `SignedContextTest`, `TenantContextTamperingTest`; branch `sec-07-stage-b` sudah di `main` | T-11; prosedur produksi masuk checklist T-05 |
| SEC-08 | `zod` rentan (GHSA-mrhx-c7xc-m3w5) | Low | Diperbaiki, menunggu CI | `zod` 4.6.7 (`5b09a3d`); `npm audit` 0 kerentanan | T-12 |
| SEC-11 | Advisory dependency baru (2026-11-17): `laravel/framework` < 13.40 (low), Symfony `http-foundation`/`http-kernel`/`routing`/`yaml` < 8.1.11 dan `mime` < 8.1.10 (termasuk trusted proxies dan host URL), `undici` < 8.12 (High, dev) | Medium | Diperbaiki, menunggu CI | `composer audit` dan `npm audit` bersih setelah update | T-23 |
| SEC-09 | Audit trail tanpa outcome, logging penolakan, hash-chain | Low | Terbuka | Tidak ada kolom outcome/hash di migrasi | Backlog P5 |
| SEC-10 | Hardening konfigurasi & instruksi agen | Low | Sebagian: instruksi agen diperbaiki (T-03, in review); checklist deployment masih terbuka | `backend/AGENTS.md` kini mencerminkan `CLAUDE.md` tanpa perintah unduh-dan-jalankan | T-05 |

## 2. Tooling Otomatis (belum ada → Sprint 1 T-04)

| Kebutuhan | Tool (gratis) | Status |
|---|---|---|
| Static analysis | SonarQube Community Build (self-hosted) | Belum |
| Secret scanning | Gitleaks | **Aktif di CI** (run pertama lulus, seluruh riwayat) |
| Dependency & container | Trivy | **Aktif di CI** (dua run pertama tidak memindai apa pun: versi action `0.28.0` tidak ada; diperbaiki di `dd8eca6` dengan pin SHA v0.36.0) |
| DAST | OWASP ZAP baseline ke lingkungan lokal/staging | Belum |
| Unit/feature test | PHPUnit, Vitest | **Aktif di CI** (lulus) |
| E2E | Playwright | Belum |
| Error monitoring | Sentry (free / self-hosted) | Belum |

Pipeline awal memakai template `D:\Workspace\templates\ci\security-pipeline.yml`. Suite backend butuh PostgreSQL dengan lima role (`README`); CI menyiapkannya sebagai service container.

## 3. Kontrol Positif (dipertahankan)
Isolasi tenant tiga lapis dengan RLS FORCE dan konteks bertanda tangan; composite FK per organisasi; test yang mengiterasi semua route dengan ID tenant lain; anti-privilege-escalation; append-only (trigger + REVOKE) untuk transaksi dan audit; identitas aset immutable; seeder demo yang menolak berjalan di produksi; tidak ada password bawaan.

## 4. Aturan untuk Fitur Baru
- Setiap tabel tenant baru wajib memakai composite FK, `enableRls`, dan GRANT eksplisit di migrasinya, serta didaftarkan di `DatabasePrivilegesTest` bila tanpa RLS (aturan migrasi `001400`).
- Setiap route baru otomatis tercakup `RouteSecurityTest`; route tanpa ID tetap perlu test permission dan scope.
- Fitur Report Designer dan Dashboard kustom wajib ditinjau security-engineer sebelum di-merge: binding data harus menghormati RLS **dan** data scope pengguna (T-01).
