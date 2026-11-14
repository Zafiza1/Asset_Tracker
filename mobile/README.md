# Asset Tracker Scanner (mobile)

Aplikasi Android/iOS untuk memindai barcode aset dan menampilkan detailnya, sama seperti halaman detail di menu **Pendataan Aset** (data aset per bagian, atribut kategori, foto, status/peminjaman, riwayat transaksi).

| | |
|---|---|
| Teknologi | Expo SDK 58, React Native 0.88, Expo Router, `expo-camera` |
| Barcode 1D | Code 128 (label cetak), Code 39, Code 93, Codabar, EAN-13, EAN-8, UPC-A, UPC-E, ITF-14 |
| Barcode 2D | QR Code (label cetak), Data Matrix, PDF417, Aztec |
| Akses | Membaca, menambah aset/kategori/lokasi, dan menindaklanjuti approval, sesuai role. Token login terikat ke satu organisasi dan berlaku 30 hari |

Setelah login, aplikasi membuka halaman **beranda** (nama akun dan organisasi, tombol **Scan Aset**, pencarian manual berdasarkan nomor seri, menu, dan tombol **Keluar**). Kamera baru aktif setelah tombol Scan Aset ditekan.

## Menu sesuai role

Menu beranda mengikuti izin akun di organisasi token (sama dengan web):

| Menu | Muncul bila | Keterangan |
|---|---|---|
| Tambah Aset | `asset.create` | Nama, No Seri, kategori, lokasi, status awal, atribut kategori (termasuk yang wajib, mis. *Kondisi Aset*), tanggal/harga perolehan, deskripsi. No Aset dibuat sistem (trigger database) dan tidak ditampilkan. Form juga punya pintasan *Tambah kategori/lokasi baru* |
| Tambah Kategori / Tambah Lokasi | `master.tenant.create` | Nama, kode (opsional, otomatis dari nama), induk, deskripsi/alamat |
| Persetujuan | `approval.manage` (Super Admin, Supervisor) | Semua pengajuan organisasi; setujui (catatan opsional) atau tolak (alasan wajib, min. 5 karakter). Tab *Pengajuan saya* untuk pengajuan sendiri |
| Pengajuan Saya | role lain (mis. Admin) | Pengajuan milik sendiri dan statusnya; pengajuan yang masih menunggu dapat dibatalkan |

Alur approval sama dengan web: penambahan oleh Super Admin/Supervisor langsung tersimpan; oleh role lain disimpan sebagai pengajuan (HTTP 202) dan baru diterapkan setelah disetujui. Badge merah di menu menunjukkan jumlah pengajuan yang menunggu.

Foto dan dokumen aset, perubahan data, arsip, impor Excel, dan transaksi tetap lewat aplikasi web.

Isi barcode adalah **ID internal aset** (UUID, seperti label dari tombol *Print barcode* di web); No Aset tidak dipakai dan tidak ditampilkan. Aplikasi mencarinya lewat `GET /api/assets/lookup?code=…`, dengan cakupan data dan izin akun yang sama seperti di web. Bila label rusak, ketik **nomor seri** aset.

## Backend

Aplikasi memakai endpoint berikut (sudah ada di `backend/`):

| Endpoint | Keterangan |
|---|---|
| `POST /api/auth/mobile/login` | `email`, `password`, `device_name`, `organization_id` (opsional). Akun dengan beberapa organisasi mendapat `409 ORGANIZATION_SELECTION_REQUIRED` beserta daftar organisasinya |
| `GET /api/auth/mobile/me` | Profil dan izin akun di organisasi token |
| `POST /api/auth/mobile/logout` | Mencabut token |
| `GET /api/assets/lookup?code=` | Detail aset berdasarkan ID aset (isi barcode) atau nomor seri (`404 ASSET_NOT_FOUND` bila tidak ada) |

Selain itu aplikasi memakai endpoint tenant yang sama dengan web: `GET /locations`, `GET /tenant-masters/categories`, `GET /tenant-masters/categories/{id}/attributes`, `POST /assets`, `POST /locations`, `POST /tenant-masters/categories`, dan `GET /approvals`, `/approvals/counts`, `/approvals/summary`, `/approvals/{id}`, `POST /approvals/{id}/approve|reject|cancel`.

Token disimpan di tabel `mobile_access_tokens` yang hanya dapat dibaca dalam konteks system (RLS), sehingga konteks tenant tidak pernah melihat token. Pada rute tenant token diterima untuk request baca (GET) dan hanya untuk write yang terdaftar di `MobileAccessToken::WRITE_ROUTES` (daftar POST di atas); write lain ditolak dengan `403 MOBILE_TOKEN_READ_ONLY`. Izin (`permission:`) dan gerbang approval (`approval:`) tetap berlaku seperti di web. Token ditolak di rute akun web (`/api/auth/logout`, `/password`, `/organizations`, dst.).

Jalankan migrasinya sekali:

```sh
cd backend
php artisan migrate --database=pgsql_owner
```

Server Laravel harus bisa dijangkau dari HP, misalnya `php artisan serve --host=0.0.0.0 --port=8000`, lalu isi **Alamat server** di layar login dengan `http://<IP-komputer>:8000`. Alamat Vite (`http://<IP>:5173`) juga bisa dipakai karena Vite meneruskan `/api` ke backend.

## Menjalankan

```sh
cd mobile
npm install
npx expo run:android      # build development + pasang ke HP/emulator (butuh Android SDK)
# atau tanpa Android Studio, build di cloud:
npx eas-cli@latest build --profile development --platform android
```

Setelah development build terpasang, `npm start` menjalankan Metro, dan perubahan kode langsung tampil di HP.

Development build **tidak berisi kode JavaScript**; ia selalu memuatnya dari Metro di komputer. Bila Metro tidak jalan (atau HP tidak satu Wi-Fi dengan komputer), aplikasi menampilkan error `Unable to load script ... index.android.bundle`. Untuk APK yang bisa dibuka tanpa komputer, buat build `preview`:

```sh
npx eas-cli@latest build --profile preview --platform android
# atau lokal (butuh Android SDK):
npx expo run:android --variant release
```

Cek sebelum commit:

```sh
npm run typecheck
npx expo-doctor
```

## Catatan

- `android.usesCleartextTraffic` aktif (`app.json`) agar HP dapat memakai server `http://` di jaringan lokal. Untuk produksi gunakan HTTPS dan matikan opsi ini.
- Folder `android/` dan `ios/` dibuat otomatis (Continuous Native Generation); jangan diedit manual, atur lewat `app.json`.
