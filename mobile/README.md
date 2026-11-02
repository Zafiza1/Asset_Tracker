# Asset Tracker Scanner (mobile)

Aplikasi Android/iOS untuk memindai barcode aset dan menampilkan detailnya, sama seperti halaman detail di menu **Pendataan Aset** (data aset per bagian, atribut kategori, foto, status/peminjaman, riwayat transaksi).

| | |
|---|---|
| Teknologi | Expo SDK 58, React Native 0.88, Expo Router, `expo-camera` |
| Barcode 1D | Code 128 (label cetak), Code 39, Code 93, Codabar, EAN-13, EAN-8, UPC-A, UPC-E, ITF-14 |
| Barcode 2D | QR Code (label cetak), Data Matrix, PDF417, Aztec |
| Akses | Hanya membaca. Token login terikat ke satu organisasi dan berlaku 30 hari |

Setelah login, aplikasi membuka halaman **beranda** (nama akun dan organisasi, tombol **Scan Aset**, pencarian nomor aset manual, dan tombol **Keluar**). Kamera baru aktif setelah tombol Scan Aset ditekan.

Isi barcode adalah **nomor aset** (seperti label dari tombol *Print barcode* di web). Aplikasi mencarinya lewat `GET /api/assets/lookup?code=…`, dengan cakupan data dan izin akun yang sama seperti di web. Nomor aset juga bisa diketik manual bila label rusak.

## Backend

Aplikasi memakai endpoint berikut (sudah ada di `backend/`):

| Endpoint | Keterangan |
|---|---|
| `POST /api/auth/mobile/login` | `email`, `password`, `device_name`, `organization_id` (opsional). Akun dengan beberapa organisasi mendapat `409 ORGANIZATION_SELECTION_REQUIRED` beserta daftar organisasinya |
| `GET /api/auth/mobile/me` | Profil dan izin akun di organisasi token |
| `POST /api/auth/mobile/logout` | Mencabut token |
| `GET /api/assets/lookup?code=` | Detail aset berdasarkan nomor aset (`404 ASSET_NOT_FOUND` bila tidak ada) |

Token disimpan di tabel `mobile_access_tokens` yang hanya dapat dibaca dalam konteks system (RLS), sehingga konteks tenant tidak pernah melihat token. Token hanya diterima untuk request baca (GET) pada rute tenant, dan ditolak di rute akun web (`/api/auth/logout`, `/password`, `/organizations`, dst.).

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
