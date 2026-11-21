# Roadmap: Fleksibel dan Mudah Dipakai

| Field | Value |
|---|---|
| Versi | 0.1 (2026-11-20) |
| Status | **Approved** ([APR-0013](../approvals/APR-0013-penanggung-jawab-aset-dan-roadmap-ux.md)) |
| Berlaku untuk | Semua fitur baru setelah Sprint 1 (Stabilisasi) |

## 1. Prinsip

### 1.1 Fleksibel untuk admin, sederhana untuk operator
| Lapisan | Siapa | Isi | Sifat |
|---|---|---|---|
| **Konfigurasi** | Super Admin, Supervisor; diatur sekali | kategori dan atribut, jenis transaksi dan alurnya, role, template dashboard/laporan | boleh kaya pilihan |
| **Operasional** | staf harian, petugas lapangan | daftar aset, pindah, pinjam, kembalikan, serah terima, scan | sesedikit mungkin keputusan per layar |

Fleksibilitas hidup di lapisan konfigurasi dan muncul ke operator sebagai **formulir dan tombol yang sudah jadi**. Fitur yang memaksa operator memilih "jenis", "mode", atau "alur" adalah tanda fleksibilitas bocor ke lapisan yang salah.

### 1.2 Aturan desain untuk setiap fitur
1. **Satu jalan untuk satu tugas.** Tidak ada dua menu yang mengerjakan hal sama (D-52).
2. **Mulai dari aset.** Aksi tersedia di detail aset dan hasil scan, hanya yang sah untuk status aset saat itu. Aksi yang tidak bisa dijalankan dinonaktifkan **beserta alasannya**.
3. **Default yang pintar.** Isi otomatis tanggal hari ini, lokasi/kategori terakhir, departemen dari penanggung jawab. Tampilkan field wajib dulu, sisanya di *Detail lainnya*.
4. **Preset sebelum builder.** Setiap builder (dashboard, laporan, transaksi) wajib punya template siap pakai yang cukup untuk sebagian besar organisasi.
5. **Label fleksibel di atas makna tetap.** Organisasi boleh mengganti nama dan menyembunyikan pilihan. Sistem memetakannya ke makna inti yang tetap, sehingga aturan, laporan, dan audit tetap konsisten.
6. **Kesalahan mudah diperbaiki.** Transaksi tetap append-only, tetapi selalu ada tombol *Koreksi* yang membuat transaksi pembalik yang sudah terisi.
7. **Bahasa awam.** Hindari *scope, tenant, generic, atribut, core type*. Contoh: "Isian tambahan", "Cakupan data".
8. **Halaman kosong memberi arah.** Setiap daftar kosong menjelaskan langkah berikutnya beserta tombolnya.
9. **Keadaan selalu jelas.** Perubahan yang menunggu persetujuan selalu diberi tanda dan bisa dilacak di *Pengajuan Saya*.

## 2. Urutan Pekerjaan
| # | Fitur | Isi | Status |
|---|---|---|---|
| 1a | **Satu model transaksi** (F-07f, F-08) | Jenis bawaan menjadi jenis sistem, Transaction Center, Designer | F-07f selesai; F-08 v0.2 menunggu APR-0012 |
| 1b | **F-09 Penanggung jawab aset** | orang + departemen, *Aset saya*, scope departemen | F-09a selesai; mockup disetujui; F-09b dan F-09c dikerjakan |
| 2 | **F-10 Multi-aset dan Koreksi** | satu transaksi untuk banyak aset (pilih dari daftar/scan beruntun); tombol *Koreksi transaksi ini* | todo, menunggu F-08 |
| 3 | **F-11 Aksi sesuai status** | panel aksi di detail aset dan hasil scan mobile (bukan hanya baca); aksi nonaktif beserta alasan | todo, menunggu F-08 |
| 4 | **F-12 Setup awal terpandu** | wizard: profil → lokasi → kategori dari **template industri** (kantor, sekolah, pabrik, klinik) → impor Excel → undang tim; checklist "Langkah awal" di Beranda | todo |
| 5 | **F-13 Status dengan label sendiri** | ganti nama/sembunyikan status per organisasi, dipetakan ke status inti | todo |
| 6 | **F-14 Kerapian** | permission per tugas (docs/08 §7 butir 2), audit istilah, halaman kosong, filter tersimpan, pencarian global (Ctrl+K) | todo |
| 7 | **Uji pengguna** | 3–5 calon pengguna, tugas konkret; target daftar 1 aset < 2 menit (project brief §2) | setelah F-11 |

Setiap fitur tetap melalui gate biasa: spesifikasi → APR → mockup (APR-0008) → implementasi → verifikasi.
