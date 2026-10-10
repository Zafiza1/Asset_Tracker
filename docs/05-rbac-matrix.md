# 05 — Role & Permission Matrix

Role adalah **kumpulan permission yang dapat dikonfigurasi**; otorisasi selalu dicek per permission + data scope, bukan per nama role. Role di bawah adalah *template* yang dikopi ke setiap organisasi baru.

## 1. Permission platform (hanya role platform)

| Permission | Platform Administrator |
|---|---|
| platform.organization.view / manage | ✅ |
| platform.user.view / manage | ✅ |
| master.generic.view / manage / release | ✅ |
| platform.audit.view | ✅ |
| platform.support.access (read-only, berbatas waktu, alasan wajib) | ✅ |

Platform Administrator **tidak** memiliki permission tenant secara implisit. Akun platform tidak terikat organisasi dan tidak dapat menerima role tenant; akses baca data tenant hanya lewat support access session.

## 2. Permission tenant

Legenda: OA = Organization Administrator · AM = Asset Manager · OP = Operator · AP = Approver · AU = Auditor · VW = Viewer. ✅ = diberikan · — = tidak.

| Permission | Arti | OA | AM | OP | AP | AU | VW |
|---|---|---|---|---|---|---|---|
| organization.view | Lihat profil organisasi | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| organization.manage | Ubah profil organisasi | ✅ | — | — | — | — | — |
| user.view | Lihat anggota | ✅ | ✅ | — | — | ✅ | — |
| user.create | Undang/buat anggota | ✅ | — | — | — | — | — |
| user.update | Ubah anggota & data scope | ✅ | — | — | — | — | — |
| user.deactivate | Suspend/nonaktifkan user | ✅ | — | — | — | — | — |
| role.view | Lihat role | ✅ | — | — | — | ✅ | — |
| role.manage | Kelola role & assignment | ✅ | — | — | — | — | — |
| catalog.generic.view | Lihat katalog Generic Master (baca saja; kode berbeda dari `master.generic.view` milik platform) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| master.tenant.view | Lihat master tenant (termasuk lokasi/cabang/dept) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| master.tenant.create | Buat master tenant | ✅ | ✅ | — | — | — | — |
| master.tenant.update | Ubah/release versi master tenant | ✅ | ✅ | — | — | — | — |
| master.tenant.archive | Arsipkan master tenant | ✅ | ✅ | — | — | — | — |
| master.tenant.migrate | Migrasi aset ke versi definisi baru | ✅ | ✅ | — | — | — | — |
| asset.view | Lihat aset (dalam scope) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| asset.create | Ajukan registrasi aset (RECEIPT) | ✅ | ✅ | ✅ | — | — | — |
| asset.update | Ubah field deskriptif | ✅ | ✅ | ✅ | — | — | — |
| asset.assign | Ajukan REASSIGN / LOAN / RETURN | ✅ | ✅ | ✅ | — | — | — |
| asset.transfer | Ajukan TRANSFER | ✅ | ✅ | ✅ | — | — | — |
| asset.status_change | Ajukan STATUS_CHANGE | ✅ | ✅ | ✅ | — | — | — |
| asset.dispose | Ajukan DISPOSAL | ✅ | ✅ | — | — | — | — |
| asset.archive | Arsipkan aset | ✅ | ✅ | — | — | — | — |
| asset.restore | Pulihkan aset arsip | ✅ | ✅ | — | — | — | — |
| document.view | Lihat/unduh dokumen | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| document.manage | Unggah/arsipkan dokumen | ✅ | ✅ | ✅ | — | — | — |
| transaction.view | Lihat transaksi (scope) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| transaction.create | Buat draft | ✅ | ✅ | ✅ | — | — | — |
| transaction.submit | Ajukan draft | ✅ | ✅ | ✅ | — | — | — |
| transaction.approve | Menyetujui (jika ditunjuk workflow) | ✅ | ✅ | — | ✅ | — | — |
| transaction.reject | Menolak / kembalikan untuk revisi | ✅ | ✅ | — | ✅ | — | — |
| transaction.post | Posting manual | ✅ | ✅ | — | — | — | — |
| transaction.reverse | Membuat reversal transaksi posted | ✅ | ✅ | — | — | — | — |
| transaction.correct | Ajukan CORRECTION | ✅ | ✅ | — | — | — | — |
| report.view | Dashboard & laporan | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| report.export | Export laporan | ✅ | ✅ | — | — | ✅ | — |
| audit.view | Audit trail organisasi | ✅ | — | — | — | ✅ | — |
| workflow.view | Lihat konfigurasi workflow | ✅ | ✅ | — | ✅ | ✅ | — |
| workflow.manage | Kelola & publish workflow | ✅ | — | — | — | — | — |
| settings.manage | Pengaturan organisasi & jenis transaksi | ✅ | — | — | — | — | — |

Catatan: permission `asset.assign/transfer/status_change/dispose` dan `transaction.correct` mengatur **siapa boleh mengajukan** jenis transaksi tersebut, karena perubahan field terkontrol hanya terjadi lewat transaksi.

## 3. Data scope

| Scope | Efek pada aset | Efek pada transaksi |
|---|---|---|
| organization | Semua aset | Semua transaksi |
| branch X | Aset dengan `branch_id = X` | Transaksi yang punya item aset dalam scope, atau diajukan user sendiri |
| department X | Aset dengan `department_id = X` | idem |
| location X | Aset di lokasi X **dan sub-lokasinya** | idem |

- Beberapa entri scope digabung (OR). Tanpa entri scope = tidak melihat aset apa pun (fail-closed).
- Untuk transaksi pindah (TRANSFER), pemohon harus punya scope atas aset **asal**; approver harus punya scope atas aset (asal **atau** tujuan, ditentukan step workflow — default asal).
- Approver harus memenuhi tiga syarat: ditunjuk oleh step aktif (role atau user tertentu), punya `transaction.approve`, dan scope mencakup item.

## 4. Aturan pelindung
1. Organisasi harus selalu punya ≥ 1 user aktif dengan role terkunci `ORG_ADMIN`.
2. User tidak dapat menambah permission ke role yang dipakainya sendiri melebihi permission yang ia miliki (anti privilege-escalation).
3. Perubahan role/permission/scope tercatat di audit dengan before/after.
