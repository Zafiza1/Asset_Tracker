# APR-0005: SEC-05 dan SEC-06 wajib ditutup sebelum produksi

| Field | Value |
|---|---|
| Tanggal diajukan | 2026-11-16 |
| Diajukan oleh | security-engineer (onboarding D-5) |
| Tahap / gate | S7 / S10 |
| Area | Security |
| Artefak | `docs/factory/07-security-status.md` |
| Status | **Approved** |
| Diputuskan oleh | Pemilik produk |
| Tanggal diputuskan | 2026-11-16 |

## Keputusan yang Diminta
Menjadikan SEC-05 (proteksi brute-force per akun) dan SEC-06 (lupa password mandiri yang aman) **syarat rilis produksi** (blocker gate S10).

## Syarat
1. SEC-06: reset password lewat email bertoken sekali pakai dan berbatas waktu, dengan respons seragam untuk email terdaftar maupun tidak (tidak membuka enumerasi, terkait SEC-02). Memerlukan mail driver produksi: dipilih yang gratis atau self-hosted (lihat APR terpisah bila berbayar).
2. SEC-05: limiter per akun di samping limiter per IP, dengan pesan yang tidak membocorkan keberadaan akun.
3. Dijadwalkan di Sprint 2 (roadmap P1/P2: T-21, T-22).

## Keputusan
"D-1 sampai D-5 saya setuju." — pemilik produk, 2026-11-16.
