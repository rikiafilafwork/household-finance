# Task Checklist

Checklist semua task dari [`Architecture.md`](./Architecture.md). Satu baris = satu GitHub issue.

- Issues: https://github.com/rikiafilafwork/household-finance/issues
- Milestones (per minggu): https://github.com/rikiafilafwork/household-finance/milestones
- Project board: https://github.com/users/rikiafilafwork/projects/8
- Ringkasan progres: [`PROGRESS.md`](./PROGRESS.md)

## Cara pakai

1. Kerjakan issue, buka PR dengan `Closes #<nomor>` di deskripsi supaya issue tertutup otomatis saat merge.
2. Jalankan `python3 scripts/sync-progress.py` untuk memperbarui checklist di bawah dan tabel di `PROGRESS.md`.
3. Jangan edit bagian di antara marker `auto:tasks` secara manual karena akan tertimpa saat sync. Task baru dibuat sebagai issue di GitHub (pakai milestone minggunya), lalu jalankan sync.

Label: `api` `web` `mobile` `shared` `infra` `test` `docs` `adr` `perf` `dogfooding` `stretch`

<!-- auto:tasks:start -->
## Minggu 1 — Fondasi (13/13)

> **Done kalau:** register → household + kategori default terbentuk; undang email kedua → accept → jadi member; token yang sama tidak bisa dipakai dua kali; login token dari curl bisa memanggil `GET /api/households`.

- [x] #1 Setup monorepo pnpm (api, web, mobile, shared) `infra`
- [x] #2 Docker Compose: app, nginx, mysql, worker, scheduler (+ mailpit dev) `infra`
- [x] #3 Bootstrap Laravel 13 + Pest + Larastan + Pint + Enum `api` `infra`
- [x] #4 Smoke test bundling Expo + packages/shared sejak awal `mobile` `shared` `infra`
- [x] #5 Helper `App\Support\Money` + unit test lengkap `api` `test`
- [x] #6 packages/shared: `money.ts` + `newClientId()` `shared` `test`
- [x] #7 Auth web: Sanctum SPA (register, login, logout) + rate limit `api`
- [x] #8 Auth mobile: personal access token `api`
- [x] #9 Migration & model: households, household_members, categories `api`
- [x] #10 Register → household pertama + owner + kategori default `api`
- [x] #11 Undangan anggota: kirim undangan via queue `api`
- [x] #12 Terima undangan + InvitationTest `api` `test`
- [x] #13 Kelola anggota: keluarkan & ubah peran + RolesTest `api` `test`

## Minggu 2 — Wallet, Transaksi, Saldo (10/10)

> **Done kalau:** semua test hijau dan k6 + reconcile menunjukkan saldo tidak meleset walau ditulis paralel.

- [x] #14 Migration wallets & transactions + CHECK constraint `api`
- [x] #15 Wallet CRUD + opening balance + arsip `api`
- [x] #16 Actions transaksi: Create/Update/Delete + saldo atomik `api`
- [x] #17 Actions transfer: Create/Update/Delete (2 baris berpasangan) `api`
- [x] #18 AdjustBalance (sesuaikan saldo) `api`
- [x] #19 Idempotensi tulis via `client_id` + ClientIdIdempotencyTest `api` `shared` `test`
- [x] #20 `GET /transactions`: filter + cursor pagination `api`
- [x] #21 Command `wallets:reconcile {--household=} {--fix}` + jadwal harian `api`
- [x] #22 BalanceConsistencyTest: semua skenario → reconcile 0 `test`
- [x] #23 Load test k6: tulis paralel ke wallet yang sama `test` `perf`

## Minggu 3 — Kategori, Budget, Policy (7/7)

> **Done kalau:** test alert dan test privasi hijau.

- [x] #24 Kategori CRUD + arsip `api`
- [x] #25 Service `VisibleWallets` (satu sumber kebenaran visibilitas) `api`
- [x] #26 Policies sesuai matriks peran `api` `test`
- [x] #27 Budget: migration + API bulanan `api`
- [x] #28 Job `CheckBudgetThresholds` + notifikasi `BudgetThresholdReached` `api`
- [x] #29 BudgetAlertTest `test`
- [x] #30 PrivateWalletVisibilityTest (list, transaksi, detail, pencarian) `test`

## Minggu 4 — Web Inti + Dogfooding (0/12)

> **Done kalau:** kamu dan pasangan mencatat pengeluaran asli selama seminggu lewat web, dan reconcile harian tetap 0.

- [ ] #31 Setup web: Vite + React + TS + Router + TanStack Query + shadcn/ui `web`
- [ ] #32 `api/client.ts`: axios + CSRF + interceptor `web`
- [ ] #33 Komponen `MoneyInput` & `MoneyText` `web` `shared`
- [ ] #34 Halaman auth: login, register, terima undangan `web`
- [ ] #35 Layout + onboarding household + pemilih household `web`
- [ ] #36 Dashboard: saldo per wallet + progres budget bulan ini `web`
- [ ] #37 Halaman transaksi: tabel, filter, tambah/edit, transfer `web`
- [ ] #38 Halaman wallet & kategori `web`
- [ ] #39 Halaman budget bulanan `web`
- [ ] #40 Anggota & undangan + lonceng notifikasi `web`
- [ ] #41 Deploy minimal + backup MySQL harian `infra`
- [ ] #42 Mulai dogfooding + `docs/dogfooding-log.md` `docs` `dogfooding`

## Minggu 5 — Transaksi Berulang (0/7)

> **Done kalau:** test hijau, dan di awal bulan transaksi berulang keluarga muncul sendiri.

- [ ] #43 Recurring rules: migration + CRUD API + Policy `api`
- [ ] #44 `RecurrenceCalculator` murni + unit test `api` `test`
- [ ] #45 Command `recurring:post-due {--date=}` idempoten `api`
- [ ] #46 Konfirmasi transaksi pending `api`
- [ ] #47 Test transaksi berulang (idempotensi, catch-up, pending) `test`
- [ ] #48 Web: halaman transaksi berulang + panel "perlu konfirmasi" `web`
- [ ] #49 Dogfooding: input transaksi berulang asli `dogfooding`

## Minggu 6 — Import CSV Mutasi Bank (0/8)

> **Done kalau:** mutasi rekening asli sebulan bisa diimpor, dan mengimpor ulang tidak menambah apa pun.

- [ ] #50 Import: migration, upload, job `ParseImport` `api`
- [ ] #51 `StatementParser` + `GenericCsvParser` `api`
- [ ] #52 Parser CSV untuk bank yang dipakai + fixture anonim `api` `test`
- [ ] #53 `FingerprintCalculator` + `DescriptionNormalizer` + unit test `api` `test`
- [ ] #54 `POST /imports/{id}/commit` `api`
- [ ] #55 Test import: dedupe & file tumpang tindih `test`
- [ ] #56 Web: wizard import (upload → preview → commit) `web`
- [ ] #57 [Stretch] `category_rules`: kata kunci → kategori `api` `web` `stretch`

## Minggu 7 — Laporan, Export, Bukti Performa (0/6)

> **Done kalau:** angka laporan cocok dengan fixture yang dihitung manual, member B tidak bisa melihat angka wallet privat A di laporan maupun export, dan `docs/benchmarks/reports.md` berisi angka + `EXPLAIN`.

- [ ] #58 API laporan: summary, by-category, trend, cash-flow `api`
- [ ] #59 Export CSV streaming `api`
- [ ] #60 Test laporan & export (akurasi + privasi) `test`
- [ ] #61 `demo:seed-large`: 200 ribu transaksi + ~1 juta noise `api` `perf`
- [ ] #62 `reports:benchmark` + `docs/benchmarks/reports.md` `docs` `perf`
- [ ] #63 Web: halaman laporan + chart + export `web`

## Minggu 8 — Mobile (Expo) (0/6)

> **Done kalau:** skenario mode pesawat dan "response hilang" tidak menghasilkan duplikat, dan quick-add dipakai harian di HP.

- [ ] #64 Setup mobile: Expo Router, auth token, pilih household `mobile`
- [ ] #65 Offline storage: expo-sqlite + cache wallet & kategori `mobile`
- [ ] #66 Antrean offline + sync engine + unit test `mobile` `test`
- [ ] #67 Layar quick-add `mobile`
- [ ] #68 Daftar transaksi + status sinkronisasi + saldo estimasi `mobile`
- [ ] #69 Uji offline manual + build APK internal (EAS) `mobile` `test` `dogfooding`

## Minggu 9 — Hardening & Presentasi (0/6)

> **Done kalau:** README dengan cerita nyata, angka, screenshot; CI hijau.

- [ ] #70 CI GitHub Actions `infra` `test`
- [ ] #71 Playwright e2e: catat → budget naik → laporan berubah `web` `test`
- [ ] #72 Hardening keamanan `api` `infra`
- [ ] #73 Operasional: uji restore, health check, log terstruktur `infra`
- [ ] #74 ADR 001–010 `docs` `adr`
- [ ] #75 README + diagram + screenshot/GIF + `make demo` `docs`
<!-- auto:tasks:end -->
