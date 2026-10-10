# 01 — Requirement Specification & Asumsi

Status: **DRAFT untuk review** · Versi dokumen: 0.1 · Tanggal: 2026-10-10

## 1. Ringkasan pemahaman sistem

Asset Tracker Enterprise V1.0 adalah platform SaaS **multi-tenant** untuk pengelolaan aset generik. Satu instalasi melayani banyak organisasi (tenant). Setiap organisasi:

- memakai **Generic Master** (katalog standar milik platform: kategori, definisi aset + atribut, jenis lokasi, satuan, kondisi standar, jenis transaksi) yang **terversi dan immutable setelah dirilis**;
- mengadopsi dan menyesuaikan definisi itu lewat **Tenant Custom Master** (subkategori, atribut tambahan, override terbatas) tanpa pernah menulis ke definisi global;
- mendaftarkan aset; setiap aset hidup di dalam **Asset Container** — batas isolasi logis yang mengikat aset ke versi definisi yang tepat (generic + tenant) beserta snapshot skemanya, sehingga perubahan master tidak pernah mengubah struktur aset lama secara diam-diam;
- mengubah data terkontrol aset (lokasi, penanggung jawab, status, kondisi, disposal, koreksi) **hanya melalui transaksi** yang melewati **workflow approval terkonfigurasi dan terversi**, lalu di-*posting* secara atomik;
- melihat dashboard/laporan yang dihitung langsung dari database, dan menelusuri semua aktivitas penting lewat **audit trail append-only**.

V1.0 = core asset management berbasis input manual. Tidak ada GPS/RFID/BLE/IoT, plugin/module runtime, prediksi AI, atau integrasi ERP.

## 2. Lingkup V1.0

### Termasuk
| Area | Ringkas |
|---|---|
| Tenancy | Organisasi, profil, cabang, departemen, lokasi, user per organisasi |
| Identitas & akses | Login session (cookie), RBAC granular, data scope (organisasi/cabang/departemen/lokasi), role platform terpisah |
| Generic Master | Kategori, definisi aset + versi + atribut, jenis lokasi, satuan, kondisi standar, jenis transaksi; release & deprecate versi |
| Tenant Custom Master | Subkategori, adopsi definisi generik, atribut tambahan, override terbatas, versi tenant, migrasi aset eksplisit |
| Aset | Registrasi (via transaksi RECEIPT), inventory, container, atribut dinamis, dokumen, riwayat, archive/restore |
| Transaksi | RECEIPT, TRANSFER, REASSIGN, STATUS_CHANGE, LOAN, RETURN, DISPOSAL, CORRECTION, REVERSAL |
| Workflow | Multi-level, per jenis transaksi + kondisi (kategori, nilai), self-approval policy, revisi/tutup, versi |
| Laporan | Dashboard, inventory per kategori/lokasi/status, pergerakan per periode, riwayat transaksi, perubahan PJ, aset tanpa lokasi/PJ, export CSV/XLSX |
| Audit | Audit trail append-only, activity feed, support-access platform yang diaudit |
| Import | Import aset CSV dengan validasi dry-run (menghasilkan transaksi RECEIPT batch) |
| Operasional | Env template, migration, seeder demo, health check, backup/restore, deployment guide |

### Tidak termasuk (roadmap — tidak boleh muncul sebagai menu aktif)
Module Management, plugin runtime, marketplace modul, GPS/RFID/BLE/IoT, live tracking, hardware SDK, AI predictive maintenance, integrasi ERP, MFA (lihat roadmap), depresiasi akuntansi.

## 3. Asumsi teknis & bisnis

Asumsi yang **mempengaruhi desain** ditandai ⚑ — mohon dikonfirmasi saat review.

| # | Asumsi | Alasan |
|---|---|---|
| A1 ✔ | **Satu user = satu perusahaan** (dikonfirmasi 2026-10-10). Akun tenant terikat permanen ke satu organisasi lewat `users.organization_id`; email unik global; tidak ada pemilih/pergantian organisasi. Pindah perusahaan = akun baru. User platform (`user_type = platform`) tidak terikat organisasi mana pun. | Keputusan bisnis; model lebih sederhana dan permukaan serangan lebih kecil (tidak ada context switching). |
| A2 ⚑ | **Nomor aset unik per organisasi**, format dikonfigurasi organisasi (default `AST-{YYYY}-{SEQ:6}`), dibangkitkan dari tabel sequence dengan row lock; tidak pernah dipakai ulang. | Kebijakan paling umum; aman terhadap concurrency. |
| A3 ⚑ | **Registrasi aset selalu melalui transaksi RECEIPT.** Jika workflow RECEIPT tanpa level approval dan user punya `transaction.post`, transaksi langsung *posted* dalam satu request (UX sama dengan "simpan aset"). | Satu jalur tunggal, draft tidak pernah mengubah inventory resmi, histori konsisten. |
| A4 ⚑ | **Perubahan resmi aset terjadi saat POSTING.** Default `auto_post_on_approval = true` per jenis transaksi; bisa diubah menjadi posting manual. | Memisahkan keputusan (approval) dari eksekusi (posting) tetapi tetap praktis. |
| A5 ⚑ | Field aset dibagi dua: **terkontrol** (lokasi, cabang, departemen, penanggung jawab, status, kondisi, definisi/kategori, nilai perolehan) hanya berubah lewat transaksi; **deskriptif** (nama, deskripsi, serial, atribut dinamis non-terkontrol, dokumen) boleh diubah langsung dengan `asset.update` + audit + optimistic lock. | Kontrol ketat pada data yang berdampak bisnis tanpa membebani koreksi typo. |
| A6 ⚑ | Satu aset hanya boleh berada dalam **satu transaksi terbuka** (submitted → approved) pada satu waktu — ditegakkan oleh partial unique index. | Mencegah dua transaksi bersaing; aturan sederhana dan dapat diuji. |
| A7 ⚑ | **Tenant harus "mengadopsi" definisi generik** menjadi `tenant_asset_definition` sebelum dipakai; definisi sepenuhnya kustom diturunkan dari definisi generik baseline `GEN-UMUM` (Aset Umum). | Setiap aset selalu punya referensi Generic Master + versi yang jelas. |
| A8 | Data scope melekat pada **user** (bukan per role). Scope = gabungan (union) entri; entri `organization` berarti seluruh data. | Model mudah dipahami admin; cukup untuk V1.0. |
| A9 | Default self-approval: **dilarang** (pemohon tidak bisa menyetujui transaksinya sendiri); bisa diizinkan per versi workflow. | Sesuai kontrol independen. |
| A10 | Platform Administrator **tidak** otomatis melihat data bisnis tenant. Akses baca data tenant hanya lewat *support access session* (alasan wajib, berbatas waktu, read-only, diaudit). | Least privilege. |
| A11 | Dokumen disimpan di disk privat (lokal atau S3-compatible), diunduh lewat endpoint yang terotorisasi; maks 20 MB/file; whitelist MIME: pdf, jpg, png, webp, docx, xlsx, csv. | Keamanan upload. |
| A12 | Mata uang nilai perolehan default mengikuti organisasi; tidak ada konversi kurs di V1.0. | Lingkup. |
| A13 | Zona waktu disimpan UTC (`timestamptz`), ditampilkan sesuai zona waktu organisasi. | Konsistensi multi-tenant. |
| A14 | Bahasa UI: Bahasa Indonesia; kode, nama tabel, dan permission berbahasa Inggris. | Konvensi. |
| A15 | Tabel `activity_logs` dan `report_definitions` dari baseline **tidak dibuat**: activity feed adalah proyeksi dari `audit_logs`; laporan V1.0 didefinisikan di kode. | Menghindari tabel tanpa fungsi jelas (sesuai §11 spesifikasi). |

## 4. Kebutuhan non-fungsional

| Kategori | Target V1.0 |
|---|---|
| Keamanan | Isolasi tenant di 3 lapis (aplikasi, constraint DB komposit, PostgreSQL RLS); OWASP ASVS L2 sebagai acuan; tidak ada secret di repo |
| Integritas | Semua operasi multi-tabel dalam DB transaction; constraint DB untuk aturan yang bisa ditegakkan DB |
| Kinerja | Daftar aset paginasi < 500 ms p95 untuk 100k aset/tenant pada hardware menengah; dashboard < 1 s |
| Ketersediaan | Single-region; backup harian + WAL/PITR opsional; RPO ≤ 24 jam (default), RTO ≤ 4 jam |
| Audit | Semua aksi pada §14 spesifikasi tercatat; retensi default 7 tahun (dapat dikonfigurasi platform) |
| Aksesibilitas | Kontras WCAG AA, navigasi keyboard, label form |
| Responsif | Desktop, tablet, mobile (≥ 360 px) |
| Observabilitas | Log terstruktur JSON dengan `request_id`, health check `/api/health` (liveness) & `/api/health/ready` (DB, storage, queue) |
