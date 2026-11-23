# Progress Tracker

Roadmap keuangan pribadi + budget planner (~10–12 jam/minggu), mulai 23 Nov 2026. Sejak 21 Nov 2026 di-pivot dari model rumah tangga; milestone **Pivot** disisipkan sebelum Minggu 3 (lihat [`docs/plans/pivot-keuangan-pribadi.md`](./docs/plans/pivot-keuangan-pribadi.md)). Detail task ada di [`TASKS.md`](./TASKS.md), desain ada di [`Architecture.md`](./Architecture.md). Board: https://github.com/users/rikiafilafwork/projects/8

## Ringkasan

Tabel di bawah diperbarui oleh `python3 scripts/sync-progress.py` (jangan diedit manual).

<!-- auto:progress:start -->
**Total: 48/91 issue selesai (53%)** `████████████████░░░░░░░░░░░░░░`

_Terakhir disinkronkan: 2026-11-23_

| Minggu | Target selesai | Progres | % | Status |
| --- | --- | --- | --- | --- |
| [Minggu 1 — Fondasi](https://github.com/rikiafilafwork/personal-finance/milestone/1) | 2026-11-29 | `████████████` 13/13 | 100% | ✅ Selesai |
| [Minggu 2 — Akun, Transaksi, Saldo](https://github.com/rikiafilafwork/personal-finance/milestone/2) | 2026-12-06 | `████████████` 10/10 | 100% | ✅ Selesai |
| [Minggu 3 (lama) — Kategori, Budget, Policy](https://github.com/rikiafilafwork/personal-finance/milestone/3) | 2026-12-13 | `████████████` 7/7 | 100% | ✅ Selesai |
| [Pivot — Keuangan Pribadi](https://github.com/rikiafilafwork/personal-finance/milestone/10) | 2026-12-13 | `████████████` 7/7 | 100% | ✅ Selesai |
| [Minggu 3 — Plan: Checklist dan Amplop](https://github.com/rikiafilafwork/personal-finance/milestone/11) | 2026-12-20 | `████████░░░░` 4/6 | 67% | 🟡 Berjalan |
| [Minggu 4 — Web Inti + Dogfooding](https://github.com/rikiafilafwork/personal-finance/milestone/4) | 2026-12-27 | `███████░░░░░` 7/12 | 58% | 🟡 Berjalan |
| [Minggu 5 — Template Bulanan + Dashboard](https://github.com/rikiafilafwork/personal-finance/milestone/5) | 2027-01-03 | `░░░░░░░░░░░░` 0/10 | 0% | ⚪ Belum mulai |
| [Minggu 6 — Import CSV Mutasi Rekening](https://github.com/rikiafilafwork/personal-finance/milestone/6) | 2027-01-10 | `░░░░░░░░░░░░` 0/8 | 0% | ⚪ Belum mulai |
| [Minggu 7 — Laporan, Export, Bukti Performa](https://github.com/rikiafilafwork/personal-finance/milestone/7) | 2027-01-17 | `░░░░░░░░░░░░` 0/6 | 0% | ⚪ Belum mulai |
| [Minggu 8 — Mobile (Expo)](https://github.com/rikiafilafwork/personal-finance/milestone/8) | 2027-01-24 | `░░░░░░░░░░░░` 0/6 | 0% | ⚪ Belum mulai |
| [Minggu 9 — Hardening & Presentasi](https://github.com/rikiafilafwork/personal-finance/milestone/9) | 2027-01-31 | `░░░░░░░░░░░░` 0/6 | 0% | ⚪ Belum mulai |
<!-- auto:progress:end -->

## Metrik Dogfooding

Diisi manual mulai minggu 4. Bahan utama untuk "cerita nyata" di README.

| Metrik | Nilai |
| --- | --- |
| Mulai dipakai keluarga sejak | - |
| Jumlah transaksi asli tercatat | - |
| Jumlah anggota aktif | - |
| Hari dengan reconcile ≠ 0 | - |
| Bug nyata ditemukan (lihat `docs/dogfooding-log.md`) | - |
| Transaksi berulang aktif | - |
| Mutasi bank yang sudah diimpor | - |

## Angka Bukti (diisi saat tersedia)

| Bukti | Target | Hasil | Sumber |
| --- | --- | --- | --- |
| k6 50 VU tulis paralel → reconcile | selisih 0 | - | `load-tests/concurrent-writes.js` |
| Laporan p95 @ 200 rb + 1 jt noise | < 100 ms | - | `docs/benchmarks/reports.md` |
| Import file sama 2x | 0 baris baru | - | `ImportDedupeTest` |
| Scheduler 2x paralel | 1 occurrence | - | `RecurringIdempotencyTest` |
| Mobile mode pesawat → online | 0 duplikat | - | uji manual minggu 8 |

## Log Mingguan

Isi di akhir setiap minggu: apa yang selesai, apa yang menghambat, apa yang digeser.

### Minggu 1 — Fondasi
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 2 — Wallet, Transaksi, Saldo
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 3 — Kategori, Budget, Policy
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 4 — Web Inti + Dogfooding
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 5 — Transaksi Berulang
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 6 — Import CSV Mutasi Bank
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 7 — Laporan, Export, Bukti Performa
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 8 — Mobile (Expo)
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**

### Minggu 9 — Hardening & Presentasi
- **Selesai:**
- **Kendala:**
- **Digeser / perubahan scope:**
