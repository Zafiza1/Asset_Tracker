# F-09: Penanggung Jawab Aset (Orang dan Departemen)

| Field | Value |
|---|---|
| Tahap | 2–7 (ringkas): requirement, alur, data, security |
| Versi | 0.1 |
| Penyusun | product-analyst, system-architect, database-engineer, security-engineer |
| Status | **Approved** ([APR-0013](../../approvals/APR-0013-penanggung-jawab-aset-dan-roadmap-ux.md)); F-09a selesai; [mockup UI](../../design/mockups/F-09-penanggung-jawab-aset.html) disetujui 2026-11-21 |
| Dasar | Keputusan pemilik produk 2026-11-20: "Perlu penanggung jawab" dan "Aku ikut saranmu" ([roadmap](../09-roadmap-fleksibel-dan-mudah-dipakai.md)) |

Bagian bertanda ⚑ adalah usulan yang perlu dikonfirmasi.

## 1. Masalah
Pertanyaan pertama orang tentang sebuah aset adalah **"dipegang siapa?"** Saat ini aset hanya punya lokasi dan status. Akibatnya:
- tidak ada orang yang bisa ditagih bila aset hilang atau rusak;
- staf tidak bisa melihat "aset yang saya pegang";
- master *Departemen* tidak terpakai dan tidak tampil di menu, dan scope data per departemen disembunyikan karena tidak memberi akses apa pun (`UserDetailPage.tsx`, `AssetScope.php`).

## 2. Definisi
| Istilah | Arti |
|---|---|
| **Penanggung jawab** | Anggota organisasi yang bertanggung jawab atas aset dalam jangka panjang (pemegang tetap). Opsional. |
| **Departemen** | Departemen pemilik/pengguna aset. Opsional. Master yang sudah ada (`departments`, datar, tanpa induk). |
| **Peminjam** | Tetap seperti sekarang: pihak yang meminjam **sementara** lewat transaksi *Peminjaman* (`borrower_name`). Peminjam ≠ penanggung jawab (D-53). |

## 3. Aturan Bisnis

### 3.1 Data
| Field | Wajib | Aturan |
|---|---|---|
| Penanggung jawab (`custodian_user_id`) | tidak | anggota organisasi berstatus **aktif** saat dipilih; boleh dikosongkan |
| Departemen (`department_id`) | tidak | departemen berstatus **aktif** saat dipilih; boleh dikosongkan |

- Keduanya diisi lewat formulir aset (tambah, ubah, ubah massal), mengikuti approval yang sama dengan data aset lain (`docs/08 §8`).
- Penanggung jawab tidak otomatis mengisi departemen ⚑. Formulir **menyarankan** departemen asal anggota (`organization_members.home_department_id`) bila departemen masih kosong.
- Anggota yang kemudian dinonaktifkan **tetap** tercatat sebagai penanggung jawab dan ditandai *(nonaktif)*, supaya tanggung jawabnya tidak hilang diam-diam. Kartu Beranda untuk admin mengingatkan aset yang penanggung jawabnya nonaktif (F-09d).
- Departemen yang masih dipakai aset aktif **tidak bisa diarsipkan**, sama dengan kategori dan lokasi (`MasterUsage::DEPARTMENT`).
- Setiap perubahan tercatat di audit dan tampil di **Riwayat** aset ("Penanggung jawab: Budi → Sari").

### 3.2 Serah terima
Sampai Transaction Center F-08 selesai, penggantian penanggung jawab dilakukan dengan mengubah data aset. Setelah itu disediakan Aksi inti **Serah terima** di mesin jenis transaksi yang sama (satu model transaksi, D-52), supaya organisasi dapat menambahkan formulir BAST atau persetujuan tanpa jalur kedua ⚑.

### 3.3 Cakupan data per departemen (F-09c) ⚑
| Scope pengguna | Melihat aset |
|---|---|
| Organisasi | semua |
| Lokasi X | lokasi X dan sub-lokasinya (tetap) |
| Departemen D | aset dengan departemen D (**baru**) |
| Beberapa entri | gabungan (OR) semuanya |

Untuk menulis (tambah aset, ubah, pindah lokasi):
- pengguna ber-scope lokasi tetap hanya boleh menempatkan aset di lokasi dalam scope-nya;
- pengguna yang hanya ber-scope departemen boleh memilih lokasi aktif mana pun, **asalkan** departemen aset berada dalam scope-nya, dan tidak boleh mengganti departemen aset ke luar scope-nya (aset tidak bisa "didorong" keluar dari jangkauannya sendiri, dan tidak bisa "ditarik" masuk dari departemen lain);
- aset tanpa lokasi **dan** tanpa departemen tetap hanya terlihat oleh scope organisasi (fail-closed).

## 4. Tampilan ([mockup F-09](../../design/mockups/F-09-penanggung-jawab-aset.html), disetujui)
- **Formulir aset:** bagian *Lokasi & Penanggung jawab*: Lokasi, Departemen, Penanggung jawab (pencarian nama/email, menampilkan jabatan).
- **Daftar aset:** kolom *Penanggung jawab*; filter Departemen dan Penanggung jawab; tab cepat **Aset saya**.
- **Detail aset:** kartu ringkas "Dipegang oleh Sari Dewi · Keuangan", dengan tanda *(nonaktif)* bila perlu.
- **Beranda:** kartu "Aset yang saya pegang" (jumlah, tautan ke *Aset saya*).
- **Master Data → Departemen** tampil kembali di menu.
- **Mobile:** hasil scan menampilkan penanggung jawab dan departemen.

## 5. API
| Endpoint | Perubahan |
|---|---|
| `POST /api/assets`, `PATCH /api/assets/{id}` | field `custodian_user_id`, `department_id` (uuid, nullable) |
| `GET /api/assets` | filter `custodian_id` (uuid), `department_id` (uuid), `mine=1` (penanggung jawab = pengguna saat ini) |
| `GET /api/assets/{id}` dan daftar | `custodian: {id, name, is_active}`, `department: {id, code, name}` |
| `GET /api/assets/custodian-options?search=` | anggota aktif untuk pemilih (nama, email, jabatan, departemen asal), butuh `asset.view` + salah satu `asset.create`/`asset.update` |

Error: `INVALID_REFERENCE` (422) untuk anggota/departemen yang tidak ada, bukan anggota organisasi, atau tidak aktif.

## 6. Data
```sql
ALTER TABLE assets ADD COLUMN custodian_user_id uuid, ADD COLUMN department_id uuid;
-- composite FK: hanya orang/departemen dari organisasi yang sama
FOREIGN KEY (organization_id, custodian_user_id) REFERENCES organization_members (organization_id, user_id)
FOREIGN KEY (organization_id, department_id)     REFERENCES departments (organization_id, id)
CREATE INDEX ON assets (organization_id, custodian_user_id);
CREATE INDEX ON assets (organization_id, department_id);
```
Tidak ada tabel baru, sehingga grant dan RLS tidak berubah.

## 7. Security
| Risiko | Kendali |
|---|---|
| Menunjuk orang dari organisasi lain | composite FK ke `organization_members` + validasi anggota aktif di service |
| Enumerasi anggota oleh pengguna tanpa hak ubah aset | `custodian-options` hanya untuk pemegang `asset.create`/`asset.update`, hanya anggota aktif, maksimal 20 hasil |
| Scope departemen membuka aset yang tidak semestinya (F-09c) | aturan tulis §3.3, test di `tests/Feature/Security`, review security-engineer sebelum merge |
| Approval dilompati | field baru lewat route yang sama (`approval:asset.update`) |

## 8. Keputusan
Lihat APR-0013: D-51..D-56.

## 9. Rencana Task
| ID | Task | Pemilik | Bergantung | Status |
|---|---|---|---|---|
| F-09a | Backend: kolom, validasi, API, filter, audit, Riwayat, `MasterUsage::DEPARTMENT`, test | backend-engineer, qa-engineer | — | done (`AssetCustodianTest`) |
| F-09b | UI web: formulir, daftar (kolom, filter, *Aset saya*), detail, menu Departemen | frontend-engineer | F-09a, mockup disetujui | done (`custodian.test.tsx`) |
| F-09c | Scope departemen (§3.3) + tampilkan kembali scope departemen di halaman pengguna | backend-engineer, security-engineer | F-09a | done (`DepartmentScopeTest`); review security di bawah |
| F-09d | Beranda: "Aset yang saya pegang" dan "Penanggung jawab nonaktif"; mobile menampilkan penanggung jawab | frontend-engineer | F-09b | Beranda done; mobile todo |
| F-09e | Excel (impor, edit massal), field Report Designer, widget *Aset per departemen* | backend-engineer, frontend-engineer | F-09a | done |

## 10. Review security F-09c (2026-11-21)
| Titik | Hasil |
|---|---|
| Filter baca (`AssetScope::apply`) | lokasi OR departemen; dipakai semua daftar, detail (global scope model), transaksi, laporan, widget, Beranda |
| Tulis: tambah, ubah departemen, pindah/kembali/masuk | `AssetScope::allowsAsset` pada hasil akhir; test push-out dan pull-in |
| Field lokasi di formulir transaksi kustom | pengguna ber-scope departemen boleh memilih lokasi mana pun; aset tetap terjangkau lewat departemennya |
| Delegasi scope (`DataScopeGuard`) | tidak berubah: departemen hanya bisa diberikan oleh pemilik scope departemen itu atau scope organisasi |
| Impor Excel | kolom *Departemen* (F-09e) dan aturan tulis yang sama lewat `AssetService::create`; pengguna yang hanya ber-scope departemen wajib mengisi departemennya |

## 11. Excel, laporan, widget (F-09e)
| Bagian | Isi |
|---|---|
| Template impor dan Multiple Edit | kolom **Departemen** (dropdown kode) dan **Penanggung Jawab (email)**. Keduanya kolom opsional: file yang diunduh sebelum F-09 tetap bisa diunggah, dan di Multiple Edit nilai yang ada tidak diubah bila kolomnya tidak ada |
| Email penanggung jawab | dicari di anggota organisasi ini (tanpa membedakan huruf besar/kecil); email asing ditolak per baris; anggota nonaktif hanya ditolak bila baru dipilih |
| Report Designer | `asset.department`, `asset.custodian`, `transaction.asset_department`, `transaction.asset_custodian` (migrasi `2026_11_21_000100`) |
| Widget | `assets_by_department` (Aset per Departemen, termasuk "Tanpa departemen"); katalog disinkronkan dengan `DashboardCatalogSeeder` |
