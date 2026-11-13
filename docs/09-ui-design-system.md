# 09 — Design System & Standar UX (Web)

Standar tampilan dan perilaku antarmuka web Asset Tracker. Tujuannya satu: setiap halaman terlihat
dan berperilaku sama, sehingga pengguna cukup belajar sekali. Semua komponen ada di
`frontend/src/components/ui/`. **Halaman baru memakai komponen ini; jangan menulis ulang gayanya
dengan class Tailwind sendiri.**

Arah visual: *modern enterprise asset management* — profesional, bersih, berbasis data, konsisten.

## 1. Fondasi

| Hal | Standar | Sumber |
|---|---|---|
| Warna utama | Biru `brand-*` (aksi utama `brand-700`) | `index.css` (`@theme`) |
| Warna netral | `slate-*`: teks `900/700`, teks sekunder `500/600`, garis `200`, latar halaman `50` | Tailwind |
| Warna status | hijau = berjalan/berhasil, kuning = perlu perhatian/menunggu, merah = bermasalah/berbahaya, abu = tidak aktif/selesai, biru = informasi/baru | `badgeTones.ts` |
| Huruf | Judul `font-display` (Lexend), isi `font-sans` (Source Sans 3); angka di tabel `tabular-nums` | `index.css` |
| Ukuran teks | Judul halaman `text-2xl`, judul kartu `text-base font-semibold`, isi `text-sm`, keterangan `text-xs` | — |
| Jarak | Kelipatan 4px. Antar kartu `gap-4`, isi kartu `p-4`/`p-5`, antar field `space-y-4` | — |
| Sudut | Kontrol & tombol `rounded-lg`, kartu & dialog `rounded-xl`, badge `rounded-full` | — |
| Bayangan | Tiga tingkat saja: `shadow-card` < `shadow-raised` < `shadow-overlay` (menu, dialog, toast) | `index.css` |
| Fokus | Garis fokus `brand-600` pada semua elemen (bawaan global), jangan dimatikan | `index.css` |

## 2. Komponen

| Komponen | File | Aturan pakai |
|---|---|---|
| Button | `Button.tsx` | `primary` (biru) = aksi utama, **maksimal satu per tampilan**. `secondary` (putih) = aksi lain. `danger` (merah) = menghapus/mencabut/menonaktifkan, selalu lewat konfirmasi. `ghost` = aksi ringan di baris/kartu. `loading` saat permintaan berjalan. |
| ButtonLink | `Button.tsx` | Tombol yang membuka halaman lain. Jangan membungkus `<Button>` dengan `<Link>`. |
| IconButton | `IconButton.tsx` | Tombol ikon saja; `label` wajib (nama aksesibel + tooltip). |
| Card | `Card.tsx` | Wadah konten: latar putih, garis tipis, `title` + `actions` di kepala. |
| PageHeader | `Card.tsx` | Judul setiap halaman: `title`, `description`, `badges` (status), `meta`, `actions`. Otomatis mengisi judul tab browser. |
| Field / Input / Select / Textarea | `Field.tsx` | Setiap isian form dibungkus `Field` (label terlihat, tanda `*`, hint, pesan error terhubung ARIA). Input dan Select berbagi satu gaya. |
| DataTable / Pagination | `Table.tsx` | Kepala tabel kontras, baris rapi, kolom bisa diurutkan, baris bisa dipilih, kolom sekunder `hideOnMobile`. |
| Filter | `Filters.tsx` | Urutan tetap: pencarian → "Filter lanjutan" → chip filter aktif + "Reset semua". |
| PillTabs / SegmentedControl | `Tabs.tsx` | `PillTabs` menyaring satu daftar per status (dengan jumlah). `SegmentedControl` berpindah tampilan data yang sama. |
| Modal | `Modal.tsx` | Satu dialog = satu tugas. Tombol di `footer`: batal di kiri, aksi utama di kanan. |
| ConfirmDialog | `ConfirmDialog.tsx` | Konfirmasi tindakan penting. Judul berupa pertanyaan, isi menjelaskan akibatnya, tombol menyebut tindakannya ("Nonaktifkan", bukan "OK"). |
| Badge / StatusBadge | `Badge.tsx` | Status selalu **warna + ikon + teks**. Pakai `StatusBadge status="…"` (peta di `badgeTones.ts`) atau `AssetStatusBadge` untuk status aset. |
| Toast | `toast.ts`, `Toaster.tsx` | Umpan balik setelah aksi: `toast.success(judul, rincian)`. Untuk perubahan yang bisa butuh persetujuan pakai `toast.saved(response, …)`. |
| Alert | `Feedback.tsx` | Pesan yang menetap di halaman (peringatan, error form). |
| EmptyState | `Feedback.tsx` | Ilustrasi + penjelasan + langkah berikutnya (`action`). |
| Skeleton | `Feedback.tsx` | `TableSkeleton` (daftar), `DetailSkeleton` (halaman detail), `FormSkeleton` (form/kartu). `Spinner` hanya untuk area kecil di dalam dialog. |
| ErrorState | `Feedback.tsx` | Gagal memuat: pesan, ID permintaan, tombol "Coba lagi" (`onRetry`). |
| RowMenu / AddMenu / ColumnToggle | — | Menu "⋯" per baris, tombol "Tambah" (form / import), pemilih kolom. |

## 3. Prinsip UX dan cara menerapkannya

1. **Minimalkan klik.** Aksi harian tersedia dari daftar lewat menu baris (mis. "Catat transaksi" di Data Aset) dan dari pencarian global. Filter tersimpan per halaman.
2. **Feedback setiap aksi.** Setiap `useMutation` yang berhasil memanggil `toast` — simpan, ubah, hapus, arsip, aktif/nonaktif, persetujuan, transaksi, import. Kegagalan tampil di tempat aksinya dilakukan (di dalam dialog/form), bukan di toast, supaya isian tidak hilang.
3. **Konfirmasi tindakan penting.** Menghapus, mengarsipkan, menonaktifkan, mencabut, melepas, dan menyetujui/membatalkan pengajuan selalu lewat `ConfirmDialog` (atau langkah konfirmasi di dialog persetujuan). Dialog tetap terbuka bila gagal.
4. **Jangan mengandalkan warna saja.** Status = warna + ikon + teks; tab terpilih punya `aria-selected` dan garis; menu aktif di sidebar punya penanda bentuk.
5. **Search mudah ditemukan.** Pencarian global di topbar (`Ctrl/⌘+K` atau `/`) mencari aset dan menu; setiap daftar punya kolom pencarian di posisi yang sama.
6. **Responsive.** Sidebar menjadi laci di layar kecil, kolom tabel sekunder disembunyikan dan nilainya pindah ke bawah nama, dialog dan toast menyesuaikan lebar layar, target sentuh minimal 40px.
7. **Permission-aware.** Tombol hanya dirender bila `can('…')` terpenuhi — termasuk tombol di empty state, menu baris, dan hasil pencarian global. API tetap menegakkan izin secara mandiri.
8. **Auditability.** Timeline aset, riwayat persetujuan, dan Audit Trail menampilkan siapa, apa, dan kapan. Dialog konfirmasi menyebut bila tindakan tercatat di audit trail.

## 4. Pola halaman

**Halaman daftar**

```
PageHeader (judul, deskripsi, aksi utama)
Card
  FilterPanel: SearchForm → AdvancedFilters → ActiveFilters
  memuat   → TableSkeleton
  gagal    → ErrorState + onRetry
  kosong   → EmptyState + aksi (tambah data pertama / hapus filter)
  ada data → DataTable + Pagination
```

**Aksi yang mengubah data**

```ts
const save = useMutation({
  mutationFn: …,
  onSuccess: async (response) => {
    await queryClient.invalidateQueries({ queryKey: […] })
    toast.saved(response, 'Aset dinonaktifkan', asset.name) // diam bila masih menunggu persetujuan
    onClose()
  },
})
```

## 5. Daftar periksa halaman baru

- [ ] Memakai `PageHeader`; hanya satu tombol `primary`.
- [ ] Keadaan memuat, gagal, dan kosong ditangani dengan komponen di atas.
- [ ] Setiap aksi memberi toast; aksi berbahaya lewat `ConfirmDialog`.
- [ ] Status memakai `StatusBadge` / `AssetStatusBadge`.
- [ ] Tombol mengikuti izin (`can`).
- [ ] Nyaman di lebar 375px; bisa dipakai dengan keyboard saja.
- [ ] Ada tes untuk alur utamanya (`npm test`).
