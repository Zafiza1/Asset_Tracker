# APR-0008: Mockup sebagai persetujuan UI; Figma menyusul (mengubah APR-0001)

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-17 |
| Diajukan oleh | project-manager, ui-ux-designer |
| Tahap / gate | S4 |
| Area | User experience |
| Mengubah | [APR-0001](APR-0001-figma-sebagai-sumber-desain.md) syarat 1 dan alur `docs/design/figma-guide.md` §5 |
| Status | **Approved** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-17 |

## Masalah
Desain Figma hanya dapat dibuat pemilik produk (agent tidak memiliki akses Figma). Selama file Figma belum ada, aturan "UI disetujui di Figma sebelum dikodekan" menahan F-01 dan F-02b tanpa batas waktu.

## Keputusan
1. Sebelum UI dikodekan, ui-ux-designer membuat **mockup HTML** yang dapat dibuka di browser, memakai token dan komponen design system (`docs/09`, `docs/design/figma-tokens.json`). Mockup mencakup keadaan utama: isi, kosong, memuat, gagal, error validasi, dan tampilan 375px.
2. Pemilik produk menyetujui mockup (pengganti frame Figma `[Approved]`). Persetujuan dicatat di dokumen fitur.
3. Figma tetap menjadi sumber kebenaran tampilan jangka panjang: halaman yang sudah disetujui lewat mockup digambar di Figma saat T-14..T-16 dikerjakan.
4. Figma **wajib lebih dulu** hanya bila pemilik produk memintanya untuk fitur tertentu.

## Dampak
- Biaya: nol.
- Risiko: desain kode dan Figma bisa berbeda sampai Figma digambar; penyimpangan dicatat sebagai issue (`figma-guide.md` §4).

## Keputusan
"Mockup, Figma menyusul." — pemilik produk, 2026-11-17.
