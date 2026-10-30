# Import Data dari Excel

Halaman **Pendataan Aset**, **Lokasi**, dan **Kategori Aset** punya tombol **Import**.
Unduh template dari modal Import ("Download Template"). Template dibuat oleh server,
jadi pilihan dropdown di dalamnya selalu sesuai data organisasi Anda saat itu.

## Isi template

| Sheet | Isi |
|---|---|
| **Data** | Tempat mengisi data, mulai baris ke-2. Judul kolom biru bertanda `*` = wajib, abu-abu = opsional. Sel berlatar biru muda punya **dropdown**; klik sel untuk melihat petunjuk kolom. Kolom tanggal dan angka sudah diberi format & validasi. |
| **Petunjuk** | Cara pengisian dan penjelasan setiap kolom. |
| **Daftar Pilihan** | Sumber dropdown (format `KODE — Nama`). Jangan diubah. |

Aturan umum:
- Format file **.xlsx**, maksimal 5MB dan 1000 baris. Jangan ubah baris judul.
- Import bersifat **semua atau tidak sama sekali**: jika ada baris salah, tidak ada data yang disimpan,
  dan semua kesalahan ditampilkan per baris ("Baris 3: Nama Aset wajib diisi.").
- Kode boleh diketik langsung (huruf besar/kecil sama saja) atau dipilih dari dropdown.

## Kolom per template

**Kategori Aset** — Nama Kategori\*, Kode, Kategori Induk, Deskripsi.
Kode dibuat otomatis dari nama jika kosong. Kategori Induk boleh berupa Kode kategori pada baris di atasnya dalam file yang sama.

**Lokasi** — Nama Lokasi\*, Jenis Lokasi\*, Kode, Lokasi Induk, Alamat.
Lokasi Induk boleh berupa Kode lokasi pada baris di atasnya dalam file yang sama.

**Pendataan Aset** — Nama Aset\*, Kategori\*, Lokasi, Status (Tersedia/Digunakan/Dalam perbaikan; default Tersedia),
Nomor Seri (unik), Tanggal Perolehan (YYYY-MM-DD), Biaya Perolehan (angka), Keterangan.
Buat kategori dan lokasi terlebih dahulu; nomor aset dibuat otomatis.
