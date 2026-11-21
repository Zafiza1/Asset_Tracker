# APR-0013: Penanggung Jawab Aset dan Roadmap Fleksibel & Mudah Dipakai

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-20 |
| Diajukan oleh | product-analyst, system-architect, ui-ux-designer |
| Tahap / gate | S2–S4 |
| Area | Produk, data, user experience |
| Artefak | [09-roadmap-fleksibel-dan-mudah-dipakai.md](../factory/09-roadmap-fleksibel-dan-mudah-dipakai.md), [F-09-penanggung-jawab-aset.md](../factory/features/F-09-penanggung-jawab-aset.md) |
| Status | **Approved** (arah dan D-51..D-56; mockup F-09 disetujui 2026-11-21) |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-20 |

## Latar
Pemilik produk meminta pondasi yang membuat Asset Tracker **fleksibel dan mudah dipakai**. Usulan prinsip dan prioritas ada di roadmap. Pemilik produk menjawab dua pertanyaan terbuka dan menyetujui sisanya: "Perlu penanggung jawab", "Boleh [satukan sistem transaksi]", "Aku ikut saranmu".

## Keputusan
| ID | Keputusan | Hasil |
|---|---|---|
| D-51 | Aset punya **penanggung jawab** (anggota organisasi) dan **departemen**, keduanya opsional | Disetujui |
| D-52 | Hanya ada **satu model transaksi**: jenis bawaan menjadi jenis sistem di mesin jenis transaksi (sudah berjalan di F-07f, dilanjutkan F-08). Fitur baru, termasuk serah terima penanggung jawab, memakai mesin itu dan tidak membuat jalur kedua | Disetujui |
| D-53 | Peminjam (sementara, lewat transaksi) dipisahkan dari penanggung jawab (tetap, data aset) | Disetujui |
| D-54 | Scope departemen melihat aset departemennya, dengan aturan tulis F-09 §3.3 | Disetujui, dikerjakan terpisah (F-09c) dengan review security |
| D-55 | Penanggung jawab yang dinonaktifkan tetap tercatat dan ditandai; admin diingatkan lewat Beranda | Disetujui |
| D-56 | Menu Master Data → Departemen ditampilkan kembali | Disetujui |

## Catatan untuk F-08 (APR-0012, masih menunggu)
D-49 mengusulkan kondisi `target.branch_differs` dan contoh "lintas cabang". Cabang sudah dihapus (migrasi `000600_remove_branches`). Setelah D-51, aset punya departemen, jadi contoh semula "lintas departemen" bisa dipakai lagi (`target.department_differs`). Perlu diselaraskan oleh pemilik APR-0012 sebelum diputuskan.

## Mockup F-09 (APR-0008), disetujui 2026-11-21
[F-09-penanggung-jawab-aset.html](../design/mockups/F-09-penanggung-jawab-aset.html): Data Aset (kolom dan filter penanggung jawab/departemen, tab *Aset saya*), formulir aset (bagian *Lokasi & penanggung jawab*, saran departemen dari anggota), detail aset (kartu *Dipegang oleh*, Riwayat), Beranda (kartu *Aset yang saya pegang* dan *Penanggung jawab nonaktif*), Master Data → Departemen. Setiap layar punya keadaan isi, error, kosong, memuat, dan lebar 375px. Keputusan pemilik produk: "a dan b" (setujui mockup, kerjakan F-09b; mulai F-09c). (2026-11-21)

## Dampak
- Biaya: nol.
- Data: dua kolom nullable di `assets`, tanpa tabel baru.
- Risiko: perluasan scope (D-54) menyentuh kontrol akses. Dimitigasi dengan task terpisah, test keamanan, dan review.

## Keputusan pemilik produk
"Perlu penanggung jawab. Boleh. Aku ikut saranmu pokoknya hasilnya menjadikan aset tracker ini fleksibel dan user friendly." (2026-11-20)
