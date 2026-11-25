# Asset Tracker Scanner (mobile)

Aplikasi Android/iOS edisi PT SIG / PT CDO: scan muat dan bongkar tabung, lapor perjalanan (dengan GPS), dan melihat detail aset dari labelnya.

| | |
|---|---|
| Teknologi | Expo SDK 58, React Native 0.88, Expo Router, `expo-camera`, `expo-location` |
| Barcode 1D | Code 128 (label cetak), Code 39, Code 93, Codabar, EAN-13, EAN-8, UPC-A, UPC-E, ITF-14 |
| Barcode 2D | QR Code (label cetak), Data Matrix, PDF417, Aztec |
| Akses | Pengiriman tabung, membaca dan menambah aset, dan menindaklanjuti approval, sesuai role. Token login terikat ke satu organisasi dan berlaku sampai pukul 23.59 hari login (zona waktu organisasi; login dengan sisa < 6 jam berlaku sampai akhir hari berikutnya). Ganti/reset password mencabut semua login mobile; menonaktifkan anggota mencabut login mobile di organisasi itu. Saat sesi berakhir, layar login menampilkan alasannya |

Setelah login, aplikasi membuka halaman **beranda**: **Pengiriman Tabung**, pencarian aset (scan label atau ketik No Manufaktur), menu, dan tombol **Keluar**. Akun supir/kernet (role *Supir / Kernet*) hanya melihat Pengiriman Tabung.

## Menu sesuai role

Menu beranda mengikuti izin akun di organisasi token (sama dengan web):

| Menu | Muncul bila | Keterangan |
|---|---|---|
| Pengiriman Tabung | `delivery.view` | Transaksi keluar/masuk yang masih berjalan. Scan dan lapor butuh `delivery.operate` |
| Cari aset | `asset.view` | Detail aset dari label atau No Manufaktur, termasuk posisi tabung (gudang, dalam perjalanan, atau customer) |
| Tambah Aset | `asset.create` | Tabung atau kendaraan, beserta atribut wajib kategorinya. No Reference dibuat sistem |
| Persetujuan | `approval.manage` (Super Admin, Supervisor) | Semua pengajuan organisasi; setujui (catatan opsional) atau tolak (alasan wajib, min. 5 karakter). Tab *Pengajuan saya* untuk pengajuan sendiri |
| Pengajuan Saya | role lain (mis. Admin) | Pengajuan milik sendiri dan statusnya; pengajuan yang masih menunggu dapat dibatalkan |

Alur approval sama dengan web: penambahan oleh Super Admin/Supervisor langsung tersimpan; oleh role lain disimpan sebagai pengajuan (HTTP 202) dan baru diterapkan setelah disetujui. Badge merah di menu menunjukkan jumlah pengajuan yang menunggu.

## Pengiriman tabung

Transaksi dibuat di web (menu Transaksi); kendaraan, supir, dan kernet dapat diisi di HP sebelum berangkat.

| | Transaksi keluar | Transaksi masuk |
|---|---|---|
| 1 | Scan muat di gudang | Isi kru, lalu **Berangkat** |
| 2 | Isi kru, lalu **Berangkat** | Per customer: **Tiba**, scan muat, **Selesai** |
| 3 | Per customer: **Tiba**, scan bongkar, **Selesai** (nama penerima) | **Tiba di gudang**, scan bongkar |
| 4 | **Selesaikan transaksi** | **Selesaikan transaksi** |

- Di jalan: **Lapor posisi**, **Kendala di jalan**, **Lanjut perjalanan**. Tiap scan dan laporan menyimpan jam dan titik GPS. Tanpa izin lokasi atau sinyal GPS, scan tetap tercatat tanpa posisi (menunggu GPS paling lama 6 detik).
- **Kirim posisi otomatis** (`src/lib/trip.tsx`): menyala di HP yang menekan Berangkat, dan bisa dinyalakan/dimatikan di detail transaksi. Posisi diperiksa tiap menit **selama aplikasi terbuka** dan dikirim bila kendaraan bergerak 150 m atau sudah 10 menit. Tanpa sinyal, posisi disimpan selama aplikasi hidup dan dikirim saat sinyal kembali. Tidak ada pelacakan di latar belakang.
- Scan dan laporan langsung dicatat di server; tanpa koneksi tidak tercatat dan harus diulang. Laporan yang diulang karena jawaban server hilang tetap tercatat sekali (`client_id`).
- Token butuh ability `delivery:write`, diberikan saat login kepada akun dengan `delivery.operate`. Akun yang baru diberi izin perlu keluar lalu masuk ulang.

Foto dan dokumen aset, perubahan data, arsip, impor Excel, Master Data, dan pembuatan transaksi tetap lewat aplikasi web.

Isi barcode adalah **ID internal aset** (UUID, seperti label dari tombol *Print barcode* di web); No Aset tidak dipakai dan tidak ditampilkan. Aplikasi mencarinya lewat `GET /api/assets/lookup?code=…`, dengan cakupan data dan izin akun yang sama seperti di web. Bila label rusak, ketik **nomor seri** aset.

## Backend

Aplikasi memakai endpoint berikut (sudah ada di `backend/`):

| Endpoint | Keterangan |
|---|---|
| `POST /api/auth/mobile/login` | `email`, `password`, `device_name`, `organization_id` (opsional). Akun dengan beberapa organisasi mendapat `409 ORGANIZATION_SELECTION_REQUIRED` beserta daftar organisasinya |
| `GET /api/auth/mobile/me` | Profil dan izin akun di organisasi token |
| `POST /api/auth/mobile/logout` | Mencabut token |
| `GET /api/assets/lookup?code=` | Detail aset berdasarkan ID aset (isi barcode) atau nomor seri (`404 ASSET_NOT_FOUND` bila tidak ada) |

Selain itu aplikasi memakai endpoint tenant yang sama dengan web: `GET /tenant-masters/categories`, `GET /tenant-masters/categories/{id}/attributes`, `POST /assets`, `GET /deliveries`, `/deliveries/{id}`, `/deliveries/options`, `PUT /deliveries/{id}/crew`, `POST /deliveries/{id}/scan|unscan|events`, dan `GET /approvals`, `/approvals/counts`, `/approvals/summary`, `/approvals/{id}`, `POST /approvals/{id}/approve|reject|cancel`.

Token disimpan di tabel `mobile_access_tokens` yang hanya dapat dibaca dalam konteks system (RLS), sehingga konteks tenant tidak pernah melihat token. Pada rute tenant token diterima untuk request baca (GET) dan hanya untuk write yang terdaftar di `MobileAccessToken::WRITE_ROUTES` (daftar POST di atas), ditambah `POST /api/transactions` jenis `outgoing`/`incoming` bagi token dengan ability `transaction:write` (`MobileAccessToken::ABILITY_ROUTES`, diberikan saat login hanya bila akun memiliki `transaction.create` dan `asset.outgoing` atau `asset.incoming`); write lain ditolak dengan `403 MOBILE_TOKEN_READ_ONLY`. Izin dicek ulang di setiap request, jadi izin yang dicabut langsung berlaku walau token masih memegang ability. Izin (`permission:`) dan gerbang approval (`approval:`) tetap berlaku seperti di web. Token ditolak di rute akun web (`/api/auth/logout`, `/password`, `/organizations`, dst.).

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

Build lama (tanpa `expo-location`) tidak dapat menjalankan versi ini: buat dan pasang build baru.

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
