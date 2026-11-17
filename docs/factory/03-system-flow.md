# System Flow: Asset Tracker (alur yang berlaku)

| Field | Value |
|---|---|
| Tahap | 3 — System Flow Design |
| Versi | 0.1 |
| Penyusun | system-architect |
| Sumber | `docs/08` §2–§9, route `backend/routes/`, per `c9d3256` |
| Status | Draft |

Dokumen ini **menggantikan** `06-lifecycle-and-workflow.md` untuk alur yang berlaku. Dokumen 06 menggambarkan workflow approval bertingkat dan state draft/submit/post yang sudah dihapus (`docs/08 §1`).

## 1. Aktor
| Aktor | Jenis | Keterangan |
|---|---|---|
| Super Admin | Manusia | Semua permission tenant, termasuk mengundang pengguna dan mengubah profil organisasi |
| Supervisor | Manusia | Memegang `approval.manage`; perubahannya langsung berlaku |
| Pengguna (Admin / role kustom) | Manusia | Perubahan aset dan master data menjadi pengajuan approval |
| Petugas mobile | Manusia | Memindai barcode, hanya baca |
| Operator platform | Manusia | Lisensi dan organisasi; tanpa akses data bisnis tenant |
| PostgreSQL (RLS, trigger) | Sistem | Menegakkan isolasi tenant, append-only, dan nomor aset |

## 2. Login dan Pemilihan Organisasi
```mermaid
sequenceDiagram
  actor U as Pengguna
  participant FE as Frontend
  participant API as Laravel API
  participant DB as PostgreSQL
  U->>FE: email + password
  FE->>API: POST /api/auth/login (throttle:login)
  API->>DB: verifikasi akun (mode system, hanya tabel akun)
  API-->>FE: session cookie + daftar organisasi
  alt lebih dari satu organisasi
    U->>FE: pilih organisasi
    FE->>API: pilih organisasi aktif
  end
  API->>DB: set konteks bertanda tangan (app.context) per request
  Note over API,DB: RLS membatasi semua query ke organisasi aktif
```

## 3. Perubahan Aset / Master Data dengan Approval
```mermaid
flowchart TD
  A[Pengguna mengirim perubahan] --> B{Punya approval.manage?}
  B -->|ya: Super Admin / Supervisor| C[Langsung diterapkan + audit]
  B -->|tidak| D[Dicoba dalam DB transaction yang di-rollback]
  D --> E{Valid?}
  E -->|tidak| F[4xx seperti biasa, tidak disimpan]
  E -->|ya| G[approval_requests: pending<br/>respons 202 approval_pending]
  G --> H{Keputusan}
  H -->|Setujui| I[Jalankan ulang panggilan sebagai pengaju]
  I --> J{Masih sah?}
  J -->|ya| K[approved + diterapkan]
  J -->|tidak| L[Gagal: tetap pending, bisa ditolak]
  H -->|Tolak| M[rejected]
  H -->|Pengaju membatalkan| N[cancelled]
```
Gambar aset tidak dicoba di tahap D (penyimpanan file tidak bisa di-rollback); validasinya baru saat disetujui.

## 4. Transaksi Aset (tanpa approval)
```mermaid
sequenceDiagram
  actor U as Pengguna (transaction.create)
  participant API as API
  participant DB as PostgreSQL
  U->>API: POST /api/transactions (jenis, aset, data)
  API->>DB: SELECT aset FOR UPDATE
  API->>API: cek aset aktif, status sesuai jenis, lokasi dalam scope, tanggal tidak di masa depan
  alt valid
    API->>DB: INSERT asset_transactions (append-only, nomor dari number_sequences)
    API->>DB: UPDATE lokasi/status/peminjam aset
    API-->>U: 201
  else tidak valid
    API-->>U: 422
  end
```
Kesalahan diperbaiki dengan transaksi baru. Transaksi tidak dapat diubah atau dihapus.

## 5. Status Aset
```mermaid
stateDiagram-v2
  [*] --> Aktif: Penambahan aset
  [*] --> TidakAktif: Penambahan aset
  [*] --> DalamPerbaikan: Penambahan aset
  Aktif --> Dipinjamkan: Peminjaman
  TidakAktif --> Dipinjamkan: Peminjaman
  Dipinjamkan --> TidakAktif: Pengembalian
  Aktif --> TidakAktif: Perubahan status
  TidakAktif --> Aktif: Perubahan status
  Aktif --> DalamPerbaikan: Perubahan status
  DalamPerbaikan --> Aktif: Perubahan status
  Aktif --> Hilang: Perubahan status
  Aktif --> Dihapuskan: Perubahan status (+asset.dispose)
  TidakAktif --> Dihapuskan: Perubahan status (+asset.dispose)
```
Pemindahan lokasi tidak mengubah status dan ditolak untuk aset Dipinjamkan, Hilang, atau Dihapuskan. *Nonaktif* (arsip) terpisah dari status dan ditolak untuk aset yang sedang dipinjam. Diagram ini menyederhanakan perubahan status; aturan lengkapnya ada di `docs/08 §4`.

## 6. Undangan Pengguna
```mermaid
sequenceDiagram
  actor SA as Super Admin
  actor P as Calon pengguna
  participant API as API
  SA->>API: undang email + role + scope + kode lisensi
  API-->>P: email undangan (token)
  P->>API: POST /api/auth/invitation/inspect
  alt belum punya akun
    P->>API: POST /api/auth/invitation/register
  else sudah punya akun
    P->>API: POST /api/auth/invitation/login
  end
  API->>API: buat keanggotaan organisasi sesuai undangan
```

## 7. Alur yang Belum Terdokumentasi (dilengkapi saat fitur di-commit)
- Dashboard kustom: membuat dashboard dari template, menambah/mengatur widget.
- Report Designer: mendesain laporan, binding data, validasi desain, pratinjau (`report.preview`).
- Aplikasi mobile: login mobile (`/api/auth/mobile/login`), pemindaian dan pencarian berdasarkan nomor seri.

## 8. Keterlacakan
| Area | Requirement (`docs/08`) | Alur |
|---|---|---|
| Login & organisasi | A1 (`docs/01`) | §2 |
| Approval | §8 | §3 |
| Transaksi | §4 | §4, §5 |
| Undangan | §9 | §6 |
