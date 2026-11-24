# APR-0012: Transaction Designer dan Transaction Center (F-08 v0.2 + v0.3)

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-20 |
| Diajukan oleh | product-analyst, ui-ux-designer, system-architect |
| Tahap / gate | S4 |
| Area | User experience, data |
| Artefak | `docs/factory/features/F-08-transaction-builder-terpandu.md` v0.3 (§1a, §1b, §3.2, §3.8–§3.11, §4, §5, §6, §8, §9), mockup [F-08-v2-transaction-designer.html](../design/mockups/F-08-v2-transaction-designer.html) |
| Status | **Approved** (D-46..D-50 dengan D-49 versi v0.3, D-57, D-58; mockup v0.3) |
| Diputuskan oleh | Pemilik produk |
| Direvisi | 2026-11-21 (v0.3, selaras APR-0013/F-09) |
| Tanggal diputuskan | 2026-11-21 |

## Latar
Pemilik produk 2026-11-20 meminta *guided customization*: Jenis transaksi, Rules, dan Workflow dipisah tetapi terhubung di satu Transaction Designer untuk administrator, sedangkan pengguna menjalankan transaksi di Transaction Center. Rule harus bernama, berdeskripsi, berprioritas, dan dapat dinonaktifkan. APR-0011 (F-08 v0.1) tetap berlaku untuk bagian yang tidak diubah v0.2.

Sebelum pengajuan ini, pemilik produk sudah memilih:
- rule bernama **di atas** mesin alur (diterjemahkan ke langkah alur), bukan mesin rule baru;
- jenis sistem dapat diubah langsung **dan** disalin sebagai jenis organisasi.

## Keputusan yang Diminta (D-46..D-50, D-57, D-58)
| ID | Keputusan | Usulan |
|---|---|---|
| D-46 | Rule bernama disimpan di versi alur dan diterjemahkan ke langkah alur (`RuleCompiler`) saat simpan/terbit; mesin F-07b, simulasi, dan versi tidak berubah | Ya |
| D-47 | Jenis sistem dapat diubah langsung dan disalin; salinan membawa Aksi inti (`core_type`) dan hanya dapat dicatat lewat alur terbitnya | Ya |
| D-48 | Workflow pengguna berupa tahapan bernama = nama langkah alur; status mesin tetap empat | Ya |
| D-49 | *(direvisi v0.3)* Subjek kondisi **departemen aset**, **penanggung jawab aset**, **pencatat adalah penanggung jawab**, dan **lintas departemen** (departemen asal penerima ≠ departemen aset), serta penyetuju *Supervisor departemen penerima*. Menggantikan `target.branch_differs`, karena cabang sudah dihapus dan aset kini punya departemen (D-51) | Ya |
| D-50 | Menu Transaksi menjadi Transaction Center; Designer tetap di Pengaturan → Jenis Transaksi untuk Super Admin/Supervisor | Ya |
| D-57 | *(v0.3)* ⚑ **Penanggung jawab aset** boleh menjadi penyetuju meskipun bukan Supervisor, hanya untuk aset yang ia pegang. Bila aset belum punya penanggung jawab atau penanggung jawabnya nonaktif, tugas jatuh ke Supervisor yang membawahi aset. Bila pencatat sendiri penanggung jawabnya, persetujuan dianggap diberikan | Ya |
| D-58 | *(v0.3)* Aksi alur **Ganti penanggung jawab** dan template *Serah terima penanggung jawab* (sesuai D-52 dan D-53). Formulir aset tetap dipakai untuk pengisian awal dan koreksi | Ya |

## Revisi v0.3 (2026-11-21)
v0.2 disusun sebelum APR-0013. Revisi ini hanya menyelaraskan bagian yang bergantung pada cabang (sudah dihapus) dan pada data aset baru dari F-09 (penanggung jawab, departemen). Bagian v0.2 lainnya tidak berubah. Catatan APR-0013 tentang D-49 sudah ditangani di sini.

## Yang Tidak Berubah dari APR-0011
Template first (D-40), kalimat dari backend (D-41), batas mode sederhana (D-42), dokumentasi tanpa AI (D-43), template buatan organisasi ditunda (D-44), kanvas F-07e sebagai mode lanjutan (D-45), dan izin D-27.

## Mockup
Mockup v0.2 memuat: galeri template berkategori; wizard 6 langkah (Informasi, Formulir, Alur/tahapan, Persetujuan, Aksi, Tinjau dengan simulasi); Designer bertab untuk jenis yang ada (Rules bernama dengan builder JIKA … MAKA …, prioritas, aktif/nonaktif); Transaction Center (Buat transaksi, Perlu tindakan saya, Transaksi saya, detail dengan tahapan) termasuk tampilan 375px. Keadaan: isi, kosong, error validasi, konflik rule. v0.3: tab Rules memuat *Persetujuan penanggung jawab* dan *Persetujuan lintas departemen* (menggantikan lintas cabang), builder kondisi menawarkan data departemen/penanggung jawab, dan pilihan penyetuju menambah *Penanggung jawab aset* serta *Supervisor departemen penerima*. Syarat APR-0008: UI v0.2 baru boleh dikodekan setelah mockup ini disetujui.

## Dampak
- Biaya: nol (tanpa dependency atau layanan baru).
- Data: `transaction_flow_versions.rules` dan `graph_source`, `transaction_type_definitions.core_type`, `settings.help` pada field; satu endpoint baru `GET /transaction-center`. v0.3: empat subjek kondisi, dua tipe penyetuju, dan satu aksi `set_custodian`, semuanya tanpa tabel baru.
- Risiko: rule dan kanvas harus menyatakan alur yang sama. Dimitigasi dengan F08-36 (pratinjau hasil penerjemahan) dan F08-39 (alur yang diubah di kanvas membuat rule hanya-baca). v0.3: penanggung jawab yang bukan Supervisor ikut menyetujui. Dimitigasi dengan F08-50 (akses hanya ke run berisi tugasnya) dan fallback F08-51/52 agar transaksi tidak macet. Ada test security.

## Keputusan
Semua usulan disetujui sesuai rekomendasi: D-46, D-47, D-48, D-49 (versi v0.3: departemen dan penanggung jawab, bukan cabang), D-50, D-57, D-58, beserta mockup v0.3. Pemilik produk: "Saya ikut rekomendasi kamu" lalu "Sesuai rekomendasi kamu" (2026-11-21). Syarat APR-0008 terpenuhi: UI v0.2/v0.3 boleh dikodekan.

## Perubahan Keputusan (2026-11-24)
**D-57 dicabut** oleh pemilik produk: "Tetap persetujuan dari superadmin dan supervisor, penanggung jawab ndak boleh menyetujui". Yang menyetujui hanya Super Admin dan Supervisor, di semua jalur persetujuan. F08-50 dan F08-51 tidak berlaku lagi:
- Penyetuju *Penanggung jawab aset* dihapus dari Designer, template, dan validasi; persetujuan otomatis bila pencatat adalah penanggung jawab ikut dihapus.
- Alur yang sudah terbit (beku) dan tugas yang masih terbuka dialihkan ke *Supervisor yang membawahi aset* (migrasi `2026_11_24_000100`).
- Pengguna yang ditunjuk alur tetapi bukan lagi Super Admin/Supervisor aktif tidak dapat menyetujui; Super Admin menggantikannya.

Penanggung jawab tetap menjadi data kondisi (D-49), penerima email, dan penerima tetap mengonfirmasi serah terima (D-58).
