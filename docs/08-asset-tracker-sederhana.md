# 08 — Asset Tracker: Arah Sederhana (berlaku mulai 2026-10-12)

Dokumen ini **menggantikan** bagian dokumen 01–07 yang bertentangan dengannya. Prinsipnya: sederhana bagi user, terstruktur bagi developer, semua pendataan dan transaksi diinput manual.

## 1. Yang berubah dibanding desain awal

| Desain awal (01–07) | Sekarang |
|---|---|
| Registrasi aset lewat transaksi RECEIPT + workflow approval | Aset langsung didaftarkan dari menu Pendataan Aset; sistem otomatis mencatat transaksi *Penambahan aset* |
| Transaksi: draft → submit → approve → post, reversal | Transaksi langsung berlaku saat disimpan; tidak dapat diubah/dihapus; kesalahan diperbaiki dengan transaksi baru |
| Asset container + versi definisi + atribut dinamis | Aset hanya memakai kolom tetap (nama, nomor seri, kategori, lokasi, kondisi, status, tanggal & nilai perolehan, deskripsi) |
| Menu tenant *Definisi Aset* & *Katalog Generic Master* | Dihapus dari menu user. API-nya masih ada (tidak dipakai UI) sampai diputuskan dihapus |
| Kategori utama wajib memilih kategori generik | Jangkar generik diisi otomatis (`UMUM`) dan tidak ditampilkan |
| Struktur cabang → departemen/lokasi, data scope per cabang | **Cabang dihapus** (migrasi `000600_remove_branches`): lokasi bertingkat dan departemen berdiri sendiri; data scope hanya organisasi, departemen, atau lokasi |

Tidak ada workflow approval, dokumen/lampiran, import/export, depresiasi, RFID/GPS, atau integrasi apa pun pada tahap ini.

## 2. Modul ("container")

Setiap modul punya folder domain, controller, file route, dan folder fitur frontend sendiri. Ketergantungan antarmodul dibuat eksplisit dan satu arah.

| Modul | Backend | Route | Frontend | Menu |
|---|---|---|---|---|
| Asset Management | `app/Domain/Assets` | `routes/modules/assets.php` | `src/features/assets` | Pendataan Aset |
| Asset Transactions | `app/Domain/AssetTransactions` | `routes/modules/transactions.php` | `src/features/transactions` | Transaksi Aset |
| Asset Analytics | `app/Domain/Analytics` | `routes/modules/analytics.php` | `src/features/dashboard` | Dashboard |
| Master Data (User Data) | `app/Domain/TenantMaster`, `app/Domain/Organization` | `routes/api.php` | `src/features/master` | Master Data |
| Generic Data (internal) | `app/Domain/Internal`, `app/Domain/GenericMaster` | — (console saja) | — | tidak ada |

Arah ketergantungan:

- **Transactions → Assets**: transaksi mengubah lokasi/status/kondisi aset (baris aset dikunci `FOR UPDATE`).
- **Assets → `TransactionLog`**: Asset Management hanya *menambahkan* catatan riwayat (penambahan, penonaktifan, pengaktifan kembali).
- **Analytics → Assets + Transactions**: hanya baca.
- **Master Data → `Shared\Masters\MasterUsage`** (interface): master tidak dapat diarsipkan selama dipakai aset aktif, tanpa mengimpor modul Assets. Implementasinya `Assets\AssetMasterUsage`, di-bind di `AppServiceProvider`.

### Isolasi saat error atau maintenance

- **Backend:** `MODULES_DISABLED=transactions` (dipisah koma) membuat endpoint modul itu menjawab `503 MODULE_UNAVAILABLE`; modul lain tetap jalan. Lihat `config/modules.php`.
- **Frontend:** setiap halaman dibungkus `ModuleBoundary` (dengan key = path), dan setiap widget dashboard punya boundary sendiri. Error render tetap di dalam halaman/widget itu: sidebar dan menu lain tetap berfungsi, user tidak dipindahkan ke halaman lain, dan error hilang saat berpindah menu.

## 3. Generic Data vs User Data

| | Generic Data (developer) | User Data (operasional) |
|---|---|---|
| Contoh | `assets.id` (UUID), `organization_id`, jangkar kategori generik, `path` hierarki, `lock_version`, `seq` transaksi, katalog Generic Master | nama aset, nomor seri/kode organisasi, kategori, lokasi, kondisi, status, tanggal & nilai perolehan, deskripsi |
| Siapa yang mengelola | Sistem; developer menelusuri | User lewat UI |
| Ditampilkan di UI tenant | Tidak | Ya |

Jaminan identitas:

- `assets.id` dan `asset_number` tidak pernah berubah (trigger DB); aset tidak pernah dihapus (trigger DB). "Hapus" = **nonaktifkan** (arsip): keluar dari inventaris aktif, riwayat tetap, bisa diaktifkan kembali.
- Nomor aset/transaksi dibangkitkan dari `number_sequences` (row-locked, tanpa duplikat) mengikuti format di Pengaturan (`AST-{YYYY}-{SEQ:6}`, `{TYPE}-{YYYY}-{SEQ:6}`).
- `asset_transactions` append-only (trigger DB). Semua referensi memakai composite FK per organisasi + RLS.

Alat developer (hanya dari server, tidak ada endpoint HTTP):

```bash
php artisan asset:inspect AST-2026-000001 --org=MAJU   # identitas, referensi teknis, rantai transaksi, cek konsistensi
php artisan asset:inspect <uuid>
php artisan asset:inspect --org=MAJU --verify-all       # putar ulang riwayat semua aset; exit 1 bila ada selisih
```

Pemeriksaan konsistensi memutar ulang transaksi (urut `seq`) dan membandingkan hasilnya dengan lokasi/status/kondisi/status-nonaktif aset saat ini.

## 4. Aturan bisnis

**Status aset** (kode tetap): Tersedia, Digunakan, Dipinjam, Dalam perbaikan, Dihapuskan. *Nonaktif* terpisah dari status.

| Transaksi | Permission tambahan | Syarat | Efek |
|---|---|---|---|
| Penambahan aset | `asset.create` | otomatis saat aset dibuat | status awal Tersedia/Digunakan/Dalam perbaikan |
| Pemindahan lokasi | `asset.transfer` | bukan Dipinjam/Dihapuskan; lokasi aktif & dalam scope; beda dari lokasi saat ini | ubah lokasi |
| Perubahan status/kondisi | `asset.status_change` (+ `asset.dispose` untuk Dihapuskan) | bukan Dipinjam; minimal satu nilai berubah | ubah status dan/atau kondisi |
| Peminjaman | `asset.assign` | status Tersedia/Digunakan; nama peminjam wajib | status Dipinjam + peminjam + rencana kembali |
| Pengembalian | `asset.assign` | status Dipinjam | status Tersedia; opsional lokasi/kondisi baru |
| Aktivitas lain | — | keterangan wajib | tidak mengubah aset |
| Penonaktifan / pengaktifan kembali | `asset.archive` / `asset.restore` | aset dipinjam tidak bisa dinonaktifkan | otomatis tercatat |

Semua transaksi manual memerlukan `transaction.create`; aset nonaktif tidak dapat ditransaksikan atau diubah. Tanggal transaksi boleh mundur, tidak boleh di masa depan.

**Data scope** berlaku lewat lokasi aset: scope lokasi = lokasi itu dan sub-lokasinya (pakai lokasi induk, mis. gedung atau kota, untuk mencakup banyak lokasi sekaligus). Aset tanpa lokasi hanya terlihat oleh user ber-scope organisasi; scope departemen saja tidak melihat aset (aset tidak memiliki departemen).

**Kategori**: master data bertingkat. Filter kategori menerima beberapa kategori sekaligus (`category_ids=a,b`) dan ikut mencakup subkategori. Menambah kategori tidak memengaruhi aset yang sudah ada; kategori yang masih dipakai aset aktif tidak bisa diarsipkan (begitu pula lokasi dan kondisi).

## 5. Dashboard (Asset Analytics)

`GET /api/dashboard` (`report.view`) dihitung langsung dari database, dalam data scope user:

- ringkasan: aset aktif, nonaktif, total nilai perolehan, ditambahkan bulan ini;
- distribusi per status, kategori, lokasi (termasuk "Tanpa lokasi"), kondisi (termasuk "Belum dinilai");
- transaksi 30 hari per jenis + 8 transaksi terbaru;
- tren 12 bulan: aset ditambahkan vs perubahan (semua transaksi lain), berdasarkan tanggal transaksi;
- perlu perhatian: peminjaman lewat batas, kondisi dengan tingkat keparahan ≥ 3 (`AssetAnalytics::POOR_CONDITION_SEVERITY`), dalam perbaikan, belum ada lokasi.

## 6. API

| Endpoint | Permission |
|---|---|
| `GET /api/assets` — `search`, `category_ids`, `location_id`, `status`, `condition_id`, `archived=only\|all`, `sort`, `page`, `per_page` | `asset.view` |
| `POST /api/assets` | `asset.create` |
| `GET /api/assets/{id}` | `asset.view` |
| `PATCH /api/assets/{id}` — data deskriptif saja, `lock_version` opsional (409 `STALE_ASSET`) | `asset.update` |
| `POST /api/assets/{id}/archive` · `/restore` | `asset.archive` · `asset.restore` |
| `GET /api/assets/{id}/transactions` | `asset.view` + `transaction.view` |
| `GET /api/transactions` — `search`, `type`, `asset_id`, `date_from`, `date_to` | `transaction.view` |
| `POST /api/transactions` · `GET /api/transactions/{id}` | `transaction.create` · `transaction.view` |
| `GET /api/dashboard` | `report.view` |

## 7. Keputusan terbuka

1. Hapus API & tabel *Tenant Definition* (definisi, versi, atribut, override) bila atribut dinamis memang tidak direncanakan.
2. Rapikan permission yang belum dipakai (`transaction.submit/approve/reject/post/reverse/correct`, `workflow.*`, `document.*`, `report.export`, `master.tenant.migrate`) agar halaman Role lebih sederhana.
3. Pengaturan `allow_self_approval_default` dan `max_upload_mb` belum berfungsi (tidak ada approval/upload).
