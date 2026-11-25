# Task Checklist

Checklist semua task dari [`Architecture.md`](./Architecture.md). Satu baris = satu GitHub issue.

- Issues: https://github.com/rikiafilafwork/personal-finance/issues
- Milestones (per minggu): https://github.com/rikiafilafwork/personal-finance/milestones
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

## Minggu 2 — Akun, Transaksi, Saldo (10/10)

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

## Minggu 3 (lama) — Kategori, Budget, Policy (7/7)

- [x] #24 Kategori CRUD + arsip `api`
- [x] #25 Service `VisibleWallets` (satu sumber kebenaran visibilitas) `api`
- [x] #26 Policies sesuai matriks peran `api` `test`
- [x] #27 Budget: migration + API bulanan `api`
- [x] #28 Job `CheckBudgetThresholds` + notifikasi `BudgetThresholdReached` `api`
- [x] #29 BudgetAlertTest `test`
- [x] #30 PrivateWalletVisibilityTest (list, transaksi, detail, pencarian) `test`

## Pivot — Keuangan Pribadi (7/7)

> **Done kalau:** semua data ter-isolasi per user dengan test IDOR di setiap route, wallet → akun, household hilang dari API & web, dan test saldo/idempotensi/concurrency + k6 tetap hijau.

- [x] #118 P1: Schema baru per user + trait `BelongsToUser` `api`
- [x] #119 P2: Hapus domain household (model, action, policy, route, undangan) `api`
- [x] #120 P3: Wallet → Akun (CRUD, arsip, sesuaikan saldo, `sort_order`) `api`
- [x] #121 P4: Adaptasi transaksi, transfer, reconcile ke akun per user `api` `test`
- [x] #122 P5: Auth pribadi: `REGISTRATION_ENABLED`, `user:reset-password`, zona waktu, kategori default `api`
- [x] #123 P6: `DataIsolationTest`: IDOR di setiap route `api` `test`
- [x] #124 P7: Web tanpa household: rute datar, Akun, zona waktu user `web`

## Minggu 3 — Plan: Checklist dan Amplop (6/6)

> **Done kalau:** semua test plan hijau dan reconcile 0 setelah rangkaian centang/batal/hapus acak.

- [x] #125 Plan items: migration + CRUD + skip + salin dari bulan lalu `api`
- [x] #126 `CheckPlanItem` / `UncheckPlanItem` (centang = transaksi) `api`
- [x] #127 `PlanComparisonQuery`: rencana vs aktual (satu sumber angka) `api`
- [x] #128 Alert amplop: `CheckEnvelopeThresholds` + notifikasi in-app `api`
- [x] #129 Test plan: centang/batal paralel, amplop tidak dobel, di luar rencana `test`
- [x] #130 Properti: reconcile 0 setelah rangkaian centang/batal/hapus acak `test`

## Minggu 4 — Web Inti + Dogfooding (12/14)

> **Done kalau:** kamu mencatat plan dan transaksi asli selama seminggu lewat web, dan reconcile harian tetap 0.

- [x] #31 Setup web: Vite + React + TS + Router + TanStack Query + shadcn/ui `web`
- [x] #32 `api/client.ts`: axios + CSRF + interceptor `web`
- [x] #33 Komponen `MoneyInput` & `MoneyText` `web` `shared`
- [x] #34 Halaman auth: login, register, terima undangan `web`
- [x] #35 Layout + onboarding household + pemilih household `web`
- [x] #36 Dashboard: saldo per wallet + progres budget bulan ini `web`
- [x] #37 Halaman transaksi: tabel, filter, tambah/edit, transfer `web`
- [x] #38 Halaman Akun & Kategori `web`
- [x] #39 Halaman Rencana (checklist + amplop + form centang) `web`
- [x] #40 Beranda sederhana + lonceng notifikasi in-app `web`
- [ ] #41 Deploy minimal + backup MySQL harian `infra`
- [ ] #42 Mulai dogfooding + `docs/dogfooding-log.md` `docs` `dogfooding`
- [x] #148 Sisa anggaran: sisa rencana + saldo semua akun + uang bebas `api` `web`
- [x] #149 Rencana: tombol + Catat di baris amplop `web`

## Minggu 5 — Template Bulanan + Dashboard (9/10)

> **Done kalau:** di awal bulan plan terisi sendiri dari template, dan Beranda menampilkan angka rencana vs aktual yang sama persis dengan halaman Rencana.

- [x] #43 Template bulanan: migration + CRUD API `api`
- [x] #44 `MonthlyDueDate` murni + unit test `api` `test`
- [x] #45 Command `plan:generate {--month=}` idempoten + tombol "Buat dari template" `api`
- [x] #46 Command `plan:auto-post` harian (memakai `CheckPlanItem`) `api`
- [x] #47 Test template: idempotensi, tanggal akhir bulan, yearly, auto-post `test`
- [x] #48 Web: halaman template bulanan (Pengaturan) `web`
- [ ] #49 Dogfooding: template asli (gaji, cicilan, internet, listrik) `dogfooding`
- [x] #131 `DashboardQuery` + `GET /api/dashboard?month=` `api`
- [x] #132 Web: Beranda lengkap (KPI, chart, perlu dicentang, amplop) `web`
- [x] #133 Test dashboard: fixture manual + sama dengan Rencana `test`

## Minggu 6 — Import CSV Mutasi Rekening (3/8)

> **Done kalau:** mutasi rekening asli sebulan bisa diimpor, dan mengimpor ulang tidak menambah apa pun.

- [x] #50 Import: migration, upload (akun rekening), job `ParseImport` `api`
- [x] #51 `StatementParser` + `GenericCsvParser` `api`
- [ ] #52 Parser CSV untuk bank yang dipakai + fixture anonim `api` `test`
- [x] #53 `FingerprintCalculator` + `DescriptionNormalizer` + unit test `api` `test`
- [ ] #54 `POST /imports/{id}/commit` `api`
- [ ] #55 Test import: dedupe & file tumpang tindih `test`
- [ ] #56 Web: wizard import (upload → preview → commit) `web`
- [ ] #57 [Stretch] `category_rules`: kata kunci → kategori `api` `stretch`

## Minggu 7 — Laporan, Export, Bukti Performa (0/6)

> **Done kalau:** angka laporan cocok dengan fixture, akun lain tidak bisa melihat angka akun demo (laporan dan export), dan docs/benchmarks/reports.md berisi angka + EXPLAIN.

- [ ] #58 API laporan: summary, by-category, trend, cash-flow, tunai vs non-tunai `api`
- [ ] #59 Export CSV streaming `api`
- [ ] #60 Test laporan & export (akurasi + isolasi) `test`
- [ ] #61 `demo:seed-large`: 200 ribu transaksi + ~1 juta noise `api` `perf`
- [ ] #62 `reports:benchmark` + `docs/benchmarks/reports.md` (laporan + dashboard) `docs` `perf`
- [ ] #63 Web: halaman laporan + chart + export `web`

## Minggu 8 — Mobile (Expo) (0/6)

> **Done kalau:** skenario mode pesawat dan "response hilang" tidak menghasilkan duplikat, Beranda mobile = web, dan Catat dipakai harian di HP.

- [ ] #64 Setup mobile: Expo Router, auth token, 5 tab `mobile`
- [ ] #65 Offline storage: expo-sqlite (antrean + snapshot dashboard, akun, kategori, plan) `mobile`
- [ ] #66 Antrean offline + sync engine (create_transaction & check_plan_item) + unit test `mobile` `test`
- [ ] #67 Layar Catat (keluar/masuk/transfer, keypad) `mobile`
- [ ] #68 Transaksi + Akun mobile: status sinkron + saldo estimasi `mobile`
- [ ] #69 Uji offline manual + build APK internal (EAS) `mobile` `test`

## Minggu 9 — Hardening & Presentasi (0/6)

> **Done kalau:** README dengan cerita nyata, angka, screenshot; CI hijau.

- [ ] #70 CI GitHub Actions `infra` `test`
- [ ] #71 Playwright e2e: centang item → saldo & dashboard berubah → batal centang `web` `test`
- [ ] #72 Hardening keamanan `api` `infra`
- [ ] #73 Operasional: uji restore, health check, log terstruktur `infra`
- [ ] #74 ADR 001–010 `docs` `adr`
- [ ] #75 README + diagram + screenshot/GIF + `make demo` `docs`
<!-- auto:tasks:end -->
