# T-11: Verifikasi Ulang SEC-01..04 dan SEC-07

| Field | Value |
|---|---|
| Tanggal | 2026-11-17 |
| Basis | `b5b0aae` (CI hijau: run 43998693946) |
| Pemverifikasi | independent-auditor (baca-saja; tidak ada kode yang diubah) |
| Acuan | `docs/asset-tracker-audit.md` §5, OWASP ASVS 5.0 L2 |

## Ringkasan

| ID | Status audit 2026-10-24 | Status sekarang |
|---|---|---|
| SEC-01 | High, terbuka | **Selesai** |
| SEC-02 | Medium, terbuka | **Sebagian**: undangan sudah seragam; registrasi publik masih membocorkan email terdaftar |
| SEC-03 | Medium, diperbaiki belum commit | **Selesai** |
| SEC-04 | Medium, diperbaiki belum commit | **Selesai** |
| SEC-07 | Diimplementasikan di branch | **Selesai** di test (PostgreSQL 17 di CI); prosedur produksi masuk T-05 |
| SEC-12 (baru) | — | Low: oracle keberadaan email lewat `PATCH /users/{id}` |

## SEC-01: Penambahan akun tanpa persetujuan
- `POST /api/users` sudah tidak ada. Menambah pengguna = undangan: `routes/api.php:103-107` (`/invitations`).
- `POST /users/{user}/organizations` (`UserController::grantOrganization`, `app/Http/Controllers/Tenant/UserController.php:92-103`) juga membuat **undangan** (`inviteMember`), bukan keanggotaan.
- Keanggotaan hanya dibuat oleh `UserManagement::addMember`, yang hanya dipanggil dari penerimaan undangan: `app/Domain/Identity/Services/OrganizationInvitations.php:480`.
- Test: `InvitationTest`, `InvitationAcceptanceTest`; lulus di CI.

**Kesimpulan:** akses ke organisasi hanya terjadi setelah pemilik email menerima undangan. Selesai.

## SEC-02: Enumerasi email
- **Undangan seragam:** `InvitationTest` membandingkan bentuk respons untuk alamat terdaftar dan tidak terdaftar, dan memastikan hanya ada satu bentuk (`assertCount(1, array_unique(...))`).
- **Masih terbuka:** `POST /api/auth/register` (publik, `throttle:register`) memvalidasi `Rule::unique('users', 'email')` dengan pesan `Email sudah terdaftar. Silakan masuk, …` (`app/Http/Controllers/Auth/AuthController.php:205,210`). Siapa pun dapat menguji apakah sebuah email terdaftar.

**Rekomendasi:** gabungkan ke T-21 (lupa password, APR-0005). Registrasi menjawab sama untuk email baru maupun lama ("Periksa email Anda"); pemilik email lama menerima email berisi tautan masuk atau reset password. Selama `SELF_REGISTRATION_ENABLED=false`, risikonya tidak ada di lingkungan tersebut.

## SEC-03: Eskalasi scope
- `UserManagement::syncScopes` (`app/Domain/Identity/Services/UserManagement.php:226-240`): menolak scope diri sendiri (`CANNOT_CHANGE_OWN_SCOPE`), `assertManageable`, lalu `scopeGuard->assertGrantable`.
- Test: `DataScopeEscalationTest` baris 81–172 (scope sendiri, di luar scope aktor, target lebih luas atau lebih berizin, referensi organisasi lain, reset password akun yang lebih luas).

## SEC-04: Lolos dari scope lokasi
- Test: `DataScopeEscalationTest` baris 367–525: memindah lokasi ke dalam atau ke luar scope, membuat lokasi di luar scope, mengubah/mengarsip/memulihkan, transfer yang memeriksa lokasi asal **dan** tujuan, RLS pada update subtree, dan scope undangan yang dicek ulang saat diterima.

## SEC-07: RLS dan konteks bertanda tangan
- Migrasi `001300_signed_database_context`, `001400_restrict_runtime_tables`, `001500_separate_session_and_queue_roles`.
- Test: `SignedContextTest`, `TenantContextTamperingTest`, `DatabasePrivilegesTest` (termasuk atribut role: `ate_app` tanpa BYPASSRLS, `ate_ctx` NOLOGIN), `DatabaseIsolationTest`; lulus di CI dengan role yang disiapkan persis seperti README.
- Belum diverifikasi: konfigurasi produksi (kunci per lingkungan, rotasi `kid`, role tanpa hak berlebih). Masuk checklist deployment (T-05).

## SEC-12 (baru, Low): Oracle keberadaan email lewat ubah data pengguna
`PATCH /api/users/{user}` memvalidasi `Rule::unique('users', 'email')->ignore($user->id)` (`UserController.php:114`) **sebelum** `assertExclusive` dan `assertManageable` di service. Dengan RLS pada `users`, validasi hanya melihat akun yang terlihat oleh organisasi; email di organisasi lain baru tertolak oleh constraint DB (`23505` → `409 DUPLICATE`, `ApiExceptionRenderer.php:80`). Aktor dengan `user.update` atas anggota eksklusif yang dapat ia kelola bisa memakai perbedaan respons itu untuk menguji keberadaan email. Dampak rendah: butuh `user.update`, mengubah email target bila berhasil, dan tercatat di audit.

Pengubahan email **tidak** membuka pengambilalihan akun: `assertExclusive` (akun hanya milik organisasi ini), `assertManageable`, dan `email_verified_at` di-reset (`UserManagement.php:120-152`).

**Rekomendasi:** perubahan email lewat konfirmasi ke alamat baru (email tetap sampai dikonfirmasi), dengan respons yang sama untuk alamat yang sudah dipakai maupun belum. Masukkan ke Sprint 2 bersama T-21.
