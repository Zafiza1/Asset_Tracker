# F-01: Dashboard Builder (UI)

| Field | Value |
|---|---|
| Tahap | 2–4 (ringkas) |
| Versi | 0.1 |
| Penyusun | product-analyst, ui-ux-designer, frontend-engineer |
| Status | **Approved** ([APR-0007](../../approvals/APR-0007-dashboard-builder-ui.md)) |

## 1. Kondisi Sekarang
| Lapisan | Status |
|---|---|
| Backend | **Selesai** (`535987c`): dashboard Overview, per kategori, kustom; template sistem/organisasi; widget katalog; share; preferensi default; `DashboardPolicy`. Test `DashboardCoreTest`, `DashboardFoundationTest` lulus di CI. |
| Frontend | **Belum terhubung**: `features/dashboard/DashboardPage.tsx` masih memakai `GET /api/dashboard` (angka tetap). Tidak ada pemanggilan `/api/dashboards`, `/dashboard-templates`, `/widget-catalog`, `/dashboard-preferences`. |

## 2. Yang Dibangun
1. **Navigasi dashboard** (dari `GET /dashboards/navigation`): Overview, per kategori, *Dashboard Saya*, *Dibagikan ke Saya*; membuka dashboard default (`/dashboards/default`).
2. **Tampilan dashboard** dengan widget dari katalog, memakai grid dan komponen widget yang sudah ada (`WidgetGrid`, `widgets.tsx`, `charts.tsx`) serta `ModuleBoundary` per widget.
3. **Mode edit** (hanya bila `manage` diizinkan policy): tambah widget dari katalog, atur ukuran/posisi, ubah konfigurasi, hapus; simpan (`PATCH /dashboards/{id}`).
4. **Buat dashboard kustom**: nama, visibilitas (pribadi/organisasi), dari template atau kosong.
5. **Template**: terapkan template (`apply-template`); kelola template organisasi bila punya `dashboard.manage`.
6. **Preferensi**: jadikan dashboard default saya.
7. Widget baru dari F-02: "Keluar sementara lewat batas", ringkasan keluar/masuk 30 hari.
8. Endpoint lama `GET /api/dashboard` dipertahankan sampai UI baru dirilis, lalu dihapus (catatan di `routes/modules/analytics.php`).

## 3. Aturan UX (mengikuti `docs/09` dan Figma)
- Satu tombol primary per tampilan ("Edit dashboard" atau "Simpan").
- Keadaan memuat/kosong/gagal per widget; error satu widget tidak menjatuhkan dashboard.
- Tombol mengikuti izin (`can('dashboard.manage')`, policy per dashboard dari respons API).
- Nyaman di 375px: grid menjadi satu kolom, mode edit hanya di layar ≥ 768px ⚑.

## 4. Sebelum Dikodekan
- Desain di Figma halaman `12 Dashboard` (T-15/T-16), status `[Approved]`.
- Putuskan temuan L-1/L-2 (T-18): pembuatan dashboard pada request GET dan daftar kategori di navigasi.

## 5. Keputusan
| ID | Keputusan | Usulan |
|---|---|---|
| D-11 | Dashboard Builder menggantikan dashboard tetap sekarang; endpoint `GET /api/dashboard` dihapus setelah rilis | Ya, setelah UI baru lulus E2E |

## 6. Task
| ID | Task | Agent | Bergantung |
|---|---|---|---|
| F-01a | T-18: putuskan dan perbaiki L-1/L-2 di backend | backend-engineer | — |
| F-01b | UI navigasi + tampilan + preferensi | frontend-engineer | desain `12 Dashboard` |
| F-01c | Mode edit, buat dashboard, template | frontend-engineer | F-01b |
| F-01d | Hapus `GET /api/dashboard` lama | backend + frontend | F-01c, E2E |
