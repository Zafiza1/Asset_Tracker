# APR-0001: Figma sebagai sumber kebenaran desain UI

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-16 |
| Diajukan oleh | ui-ux-designer (via project-manager, onboarding D-3) |
| Tahap / gate | S4 — UI/UX Design |
| Area | User experience |
| Artefak | `docs/factory/00-onboarding-report.md` §5 D-3 |
| Status | **Approved**, diubah oleh [APR-0008](APR-0008-mockup-sebelum-figma.md) (mockup sebagai persetujuan UI sampai Figma tersedia) |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-16 |

## Keputusan yang Diminta
Memakai Figma sebagai sumber kebenaran desain UI web Asset Tracker, menggantikan pendekatan "design system di kode saja".

## Opsi
| Opsi | Biaya | Risiko | Dapat dibalik |
|---|---|---|---|
| A. Design system di kode saja (rekomendasi awal) | 0 | Desain baru tidak bisa direview sebelum dikodekan | Ya |
| **B. Mulai pakai Figma (dipilih)** | 0 (paket Starter gratis) | Desain dan kode bisa tidak sinkron; batas file paket Starter | Ya |

## Dampak
- Biaya: nol. Paket Figma Starter dan plugin Tokens Studio versi gratis.
- Proses: perubahan UI yang terlihat pengguna didesain dan disetujui di Figma (gate S4) sebelum dikodekan.
- Sinkronisasi: token di `frontend/src/index.css` dan `docs/design/figma-tokens.json` harus sama (lihat `docs/design/figma-guide.md` §4).

## Syarat
1. Kode tetap menjadi sumber kebenaran **perilaku** (state, permission, aksesibilitas). Figma menjadi sumber kebenaran **tampilan dan tata letak**.
2. Perubahan token dilakukan di kedua tempat dalam satu PR.
3. Tidak beralih ke paket Figma berbayar tanpa approval record baru (area biaya).

## Keputusan
"Saya setuju mulai pakai Figma." — pemilik produk, 2026-11-16.
