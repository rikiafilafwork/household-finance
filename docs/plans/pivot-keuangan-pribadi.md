# Rencana Perubahan: Keuangan Rumah Tangga → Keuangan Pribadi + Budget Planner

Status: **disetujui (21 Nov 2026)**. Acuan: `Architecture.md` versi baru.

Keputusan:
1. Migration **ditulis ulang** sesuai schema baru; DB dev & test di-reset.
2. Issue terbuka **diedit di tempat**; yang konsepnya hilang ditutup *not planned*; issue baru untuk Fase P dan Plan.
3. Repo diganti nama menjadi **`personal-finance`** (GitHub me-redirect nama lama).

## 1. Ringkasan

Perubahannya lebih besar dari "hapus multi-user". Ada tiga pergeseran sekaligus:

| Aspek               | Sekarang (sudah dibangun)                                         | Arsitektur baru                                                                                     |
| ------------------- | ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Pemilik data        | Household + anggota (owner/member), undangan email                | Satu user = satu pemilik data (`user_id` di setiap tabel), tanpa undangan                           |
| Privasi             | Wallet shared/private + `VisibleWallets` + matriks peran          | Isolasi antar user (`BelongsToUser` global scope), tes IDOR di setiap route                          |
| Tempat uang         | `wallets` (cash/bank/ewallet, shared/private)                     | `accounts` (bank/ewallet/cash), `sort_order`, nama unik per user, saldo negatif hanya diperingatkan |
| Anggaran            | `budgets` per kategori per bulan + alert 80/100                   | **Rencana bulanan**: item `checklist` (centang → transaksi) dan `envelope` (amplop per kategori)    |
| Berulang            | Rencana Minggu 5: `recurring_rules` + transaksi `pending`         | `plan_templates` (monthly/yearly) + `plan:generate` + `auto_post`; **tidak ada** status `pending`  |
| Dashboard           | Disusun di klien dari beberapa endpoint                           | `GET /api/dashboard?month=` lewat `DashboardQuery` → `PlanComparisonQuery` (satu sumber angka)      |
| Notifikasi          | database + email (Mailpit), undangan via email                    | In-app saja (lonceng); reconcile → banner `reconcile_ok` di dashboard; **tanpa email**              |
| Timezone            | `households.timezone`                                             | `users.timezone`                                                                                    |
| Auth                | Register selalu terbuka                                           | `REGISTRATION_ENABLED`, command `user:reset-password` (tanpa email)                                 |

## 2. Yang dipertahankan (nilai terbesar tetap utuh)

Bagian "teknis berat" yang jadi nilai jual portofolio hampir semuanya terpakai ulang, hanya diganti nama/kolomnya:

- `Money` (PHP) + `money.ts`, aturan lint larangan float, `MoneyInput`/`MoneyText`.
- Tanda amount + CHECK constraint, saldo denormalisasi + `lockForUpdate` berurutan id, transfer 2 baris.
- Idempotensi `client_id` (cek setelah kunci + tangkap `UniqueConstraintViolation`), termasuk test paralel `Process::pool` dan k6.
- Reconcile + `--fix` + jadwal harian; `BalanceConsistencyTest` (skenario + acak) dan kontrol negatifnya.
- Auth Sanctum SPA + token mobile, rate limit, `SpaClient` test helper, pesan validasi Bahasa Indonesia.
- Kategori (CRUD + arsip + "transaksi lama tetap menunjuk kategori diarsip").
- Logika alert tepat-sekali (`insertOrIgnore` + unique), dipindah dari `budget_id` ke `plan_item_id`.
- Web: `api/client.ts` (CSRF, interceptor), `lib/dates.ts`, guard auth, halaman login/daftar, halaman Transaksi (filter, dialog, `client_id` per form), shadcn + Tailwind, Docker/nginx, Makefile.

## 3. Yang dihapus

| Area      | Item                                                                                                                                                                                                                                                                 |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend   | `households`, `household_members`, `household_invitations`; `HouseholdRole`, `WalletVisibility`, `TransactionStatus`; Actions `Households/*`; `HouseholdPolicy`, `WalletPolicy`, `TransactionPolicy` (diganti scope per user), `VisibleWallets`; `InvitationSent`; email (Mailpit tidak lagi wajib) |
| Kolom     | `wallets.owner_user_id`, `visibility`; `transactions.household_id`, `status`, `created_by`, `updated_by`, `recurring_rule_id`                                                                                                                                         |
| Budget    | `budgets`, `SetMonthlyBudgets`, `CopyBudgetsFromPreviousMonth`, `BudgetSpending`, `BudgetPolicy`, endpoint `/budgets/*` (konsepnya pindah ke amplop)                                                                                                                 |
| Test      | `Households/*`, `Policies/RoleMatrixTest`, `Privacy/PrivateWalletVisibilityTest`, `Privacy/ClientIdReplayPrivacyTest` (diganti `Isolation/DataIsolationTest`), `Budgets/*` (diganti test Plan)                                                                        |
| Web       | Rute `/h/:householdId`, pemilih household, onboarding household, halaman undangan, rencana halaman Anggota                                                                                                                                                          |

## 4. Strategi database: tulis ulang migration (rekomendasi)

Belum ada data asli (dogfooding belum mulai), jadi migration **ditulis ulang** agar sama persis dengan schema di `Architecture.md`, lalu `make fresh`. Alternatif migration tambahan (rename kolom, drop tabel) hanya menambah riwayat yang membingungkan tanpa manfaat. Riwayat lama tetap ada di git dan PR #76–#116.

Konsekuensi: database dev & test di-reset; akun uji di DB dev hilang (seeder baru membuat akun demo).

## 5. Urutan kerja

### Fase P — Pivot (sisipan sebelum Minggu 3 baru, ±1 minggu)

| ID  | Pekerjaan                                                                                                                                                                                                                         | Test kunci                                                                                         |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| P1  | Schema baru (tulis ulang migration): `users.timezone`, `accounts`, `categories`, `transactions` (+`user_id`, `account_id`, `plan_item_id`), CHECK & index sesuai dokumen. Trait `BelongsToUser` (global scope + auto `user_id`)   | Schema test (CHECK, unique), scope: query tanpa login tidak bocor                                 |
| P2  | Hapus domain household (model, action, policy, route, notifikasi undangan, test). Route jadi `/api/accounts`, `/api/transactions`, `/api/transfers`, `/api/categories` dengan binding ter-scope                                   | —                                                                                                  |
| P3  | Wallet → Account: CRUD, arsip, `AdjustBalance`, `sort_order`, nama unik per user, `AccountType` (bank/ewallet/cash); `LockWallets` → `LockAccounts`                                                                              | Test akun + adjust (diadaptasi)                                                                    |
| P4  | Transaksi & transfer diadaptasi (tanpa `status`/`created_by`; invariant tanpa `status='posted'`); `accounts:reconcile` + flag `reconcile_ok` (tanpa email); `TransactionQuery` per user                                          | `BalanceConsistencyTest`, `ClientIdIdempotencyTest`, `TransferTest`, Concurrency, k6 tetap hijau   |
| P5  | Auth: `REGISTRATION_ENABLED`, `user:reset-password`, kategori default baru (8 pengeluaran + 3 pemasukan), timezone user di `/auth/me` + ubah zona waktu                                                                            | Register mati → 403/404; reset password via command                                               |
| P6  | `Isolation/DataIsolationTest`: setiap route × akun lain → 404 (baca & tulis), termasuk replay `client_id` milik user lain                                                                                                         | Penjaga: route baru tanpa entri test isolasi → test gagal                                         |
| P7  | Web: hapus household (rute, switcher, onboarding, undangan); rute datar `/`, `/transactions`, `/accounts`, `/plan`, `/settings/*`; Wallet → Akun di UI; tanggal dari `users.timezone`                                             | Test routing & form disesuaikan; uji browser login → catat → saldo                                |

### Minggu 3 baru — Plan: Checklist dan Amplop

Sesuai `Architecture.md` § Minggu 3: `plan_items` (+ generated column `envelope_category_id`), `CheckPlanItem` / `UncheckPlanItem` / `SkipPlanItem` / `CopyPlanFromPreviousMonth`, `PlanComparisonQuery`, `CheckEnvelopeThresholds` + `budget_alerts (plan_item_id, threshold)`, notifikasi in-app, dan seluruh daftar test (centang paralel → 1 transaksi + 409, batal centang → `plan_item_id = NULL`, tidak dobel hitung amplop, fixture 4.075.000 / 1.025.000, alert 79/80/100). `DeleteTransaction` ikut menyetel `plan_item_id = NULL`.

### Minggu 4 — Web inti + dogfooding

Halaman Akun, Transaksi (+ badge "terhubung ke rencana"), **Rencana** (checklist + amplop + form centang), Beranda sederhana, Kategori (Pengaturan), lonceng in-app, deploy + backup, dogfooding log. Invalidasi: `accounts`, `transactions`, `plan`, `dashboard`.

### Minggu 5–9

Sesuai `Architecture.md`: template + `plan:generate` + `auto_post` + dashboard lengkap (M5), import CSV hanya akun bank (M6), laporan + export + benchmark termasuk dashboard (M7), mobile 5 tab + antrean `create_transaction` & `check_plan_item` (M8), hardening + ADR baru (M9).

**Dampak jadwal:** Fase P menggeser semuanya ±1 minggu (total 10 minggu). Bisa ditutup dengan menjadikan import CSV (M6) stretch, seperti yang sudah disebut di `Architecture.md`.

## 6. Perubahan GitHub (issue, milestone, board)

- Issue #1–#37 yang sudah closed **dibiarkan** sebagai riwayat.
- Milestone baru **"Pivot: Keuangan Pribadi"** berisi P1–P7 (7 issue baru).
- Milestone diganti nama: 2 → "Akun, Transaksi, Saldo"; 3 → "Plan: Checklist dan Amplop" (+ issue baru); 5 → "Template Bulanan + Dashboard"; 6 → "Import CSV Mutasi Rekening".
- Issue terbuka di-**edit di tempat** kalau padanannya dekat (nomor & posisi di board tetap), ditutup *not planned* kalau konsepnya hilang:

| Issue        | Tindakan                                                                                                                   |
| ------------ | -------------------------------------------------------------------------------------------------------------------------- |
| #38          | Edit → "Halaman Akun & Kategori"                                                                                            |
| #39          | Edit → "Halaman Rencana (checklist + amplop + form centang)"                                                                |
| #40          | Edit → "Beranda sederhana + lonceng notifikasi in-app"                                                                      |
| #41, #42     | Tetap (+ `REGISTRATION_ENABLED=false` di deploy)                                                                            |
| #43–#48      | Edit → template CRUD, `MonthlyDueDate`, `plan:generate`, `plan:auto-post` (menggantikan konfirmasi pending), test template, web template |
| #49          | Edit → "Dogfooding: template asli (gaji, cicilan, internet, listrik)"                                                       |
| baru (M5)    | `DashboardQuery` + `GET /dashboard`; Web Beranda lengkap; test dashboard (fixture + sama dengan Rencana)                     |
| baru (M3)    | ±6 issue Plan sesuai § Minggu 3                                                                                             |
| #50–#57      | Tetap, istilah wallet → akun rekening (bank saja)                                                                           |
| #58–#63      | Edit: + rencana vs aktual, tunai vs non-tunai, benchmark dashboard, test isolasi laporan/export                             |
| #64–#69      | Edit: tanpa pilih household; snapshot dashboard; antrean 2 jenis item; + layar Beranda & Rencana mobile; skenario 409       |
| #70–#75      | Edit: Playwright alur centang; IDOR per route; daftar ADR baru                                                              |

- `TASKS.md` / `PROGRESS.md` di-generate ulang; ringkasan tujuan di atasnya diganti.

## 7. Risiko & catatan

- **Ganti nama repo** → `personal-finance`: perbarui `REPO` di `scripts/sync-progress.py`, link board/milestone di `TASKS.md`/`PROGRESS.md`, judul project board, `git remote` lokal, nama project Docker Compose (network `household-finance_default` dipakai `make load-test`), dan `APP_NAME`/nama cookie sesi. Folder lokal di-rename oleh Anda.
- Fase P menyentuh hampir semua test. Kontrol negatif (suntik bug) diulang untuk test saldo/idempotensi supaya tidak ada test yang diam-diam jadi kosong saat diadaptasi.
- "Budget" lama dan "amplop" baru mirip tapi beda definisi aktual (amplop mengecualikan transaksi yang terhubung ke checklist). Logikanya ditulis ulang di `PlanComparisonQuery`, tidak ditambal dari `BudgetSpending`.
