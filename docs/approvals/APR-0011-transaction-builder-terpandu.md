# APR-0011: Transaction Builder Terpandu (F-08)

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-20 |
| Diajukan oleh | product-analyst, ui-ux-designer, system-architect, security-engineer |
| Tahap / gate | S4 |
| Area | User experience, data |
| Artefak | `docs/factory/features/F-08-transaction-builder-terpandu.md` v0.1, mockup [F-08-transaction-builder-terpandu.html](../design/mockups/F-08-transaction-builder-terpandu.html) |
| Status | **Approved** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-20 |

## Latar
Pemilik produk 2026-11-20: Custom Transaction Builder boleh *powerful*, tetapi UX-nya harus template first, guided setup, human-readable rules, contextual configuration, simulation, auto documentation, dan simple by default / advanced on demand. Builder F-07 baru memenuhi *simulation* penuh; prinsip lain belum atau sebagian (F-08 §1).

## Keputusan yang Diminta (D-40..D-45)
| ID | Keputusan | Usulan |
|---|---|---|
| D-40 | Template awal ditulis di kode dan dirilis bersama aplikasi; organisasi memakai salinannya | Ya |
| D-41 | Kalimat aturan dan dokumentasi dibangkitkan di backend (`FlowDescriber`), satu sumber untuk web, ponsel, dan email | Ya |
| D-42 | Batas mode sederhana: alur lurus, prasyarat, maks. 2 persetujuan, satu syarat persetujuan tambahan, aksi ke aset, satu *Isi data* lanjutan, satu *Tunggu*. Selebihnya hanya di mode lanjutan | Ya |
| D-43 | Dokumentasi deterministik dari data, tanpa AI/LLM (mode tanpa biaya) | Ya |
| D-44 | Template buatan organisasi ditunda ke fase berikutnya | Ya |
| D-45 | F-08 dikerjakan setelah F-07f dan menggantikan halaman `/transaksi/jenis/:id`; kanvas F-07e dipertahankan sebagai mode lanjutan | Ya |

## Mockup
Mockup mencakup: titik awal + galeri template, formulir dengan pratinjau, aturan mode sederhana dengan kalimat, alur lanjutan, uji & terbitkan dengan skenario otomatis, dan *Cara kerja* (termasuk 375px, hanya dilihat). Keadaan: isi, error validasi, kosong, memuat. Syarat APR-0008: UI baru boleh dikodekan setelah mockup disetujui.

## Dampak
- Biaya: nol (tanpa dependency atau layanan baru).
- Mesin alur, izin, dan RLS tidak berubah; satu kolom baru `transaction_type_definitions.source_template_key`.
- Risiko: mode sederhana dan kanvas harus menyatakan alur yang sama; dimitigasi dengan F08-28/F08-29 (alur di luar mode sederhana hanya dibaca di mode itu).

## Keputusan
"Ya." (pemilik produk, 2026-11-20): mockup dan D-40..D-45 disetujui. Syarat APR-0008 terpenuhi; UI F-08 boleh dikodekan setelah F-07f (D-45).
