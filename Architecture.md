# Keuangan Rumah Tangga — Roadmap 9 Minggu (FINAL)

Status: **final** setelah review stack dan scope (21 Nov 2026).

Asumsi: ~10–12 jam/minggu (kerja + freelance + kuliah jalan terus). Minggu 1–2 itu wilayah Laravel yang sudah kamu kuasai, jadi kalau lebih longgar bisa digabung.

Stack: Laravel 13, MySQL 8, Pest, Sanctum (cookie web + token mobile), queue & cache driver `database`, React + Vite + TanStack Query + shadcn/ui + Recharts, React Native (Expo), Docker Compose.

Tujuan portofolio: project "teknologi biasa tapi rapi" yang **kamu pakai sendiri**. Nilai jualnya bukan arsitektur terdistribusi, tapi: uang dimodelkan benar, saldo tidak pernah meleset (dan ada buktinya), laporan cepat tanpa trik, dan mobile offline yang tidak bikin data dobel. Ditambah cerita nyata di README: "dipakai keluarga sendiri sejak minggu ke-4, N transaksi, bug nyata yang ketemu".

---

## Hasil Review Stack

Pertanyaannya: apakah tiap komponen dibutuhkan oleh requirement, atau cuma kelihatan keren? Kesimpulan: **stack sesuai dan tidak overkill**. Semua komponen punya alasan yang bisa ditunjuk ke requirement, dan semua yang berat sengaja tidak dipakai.

| Komponen                                                       | Verdict                           | Alasan                                                                                                                                                                                                                                                             | Kapan keputusan ini dibalik                                                                   |
| -------------------------------------------------------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| Laravel 13 + MySQL 8 + Pest                                    | Pas                               | Data relasional, saldo butuh transaksi ACID + `lockForUpdate`. MySQL 8 sudah punya CHECK constraint, window function, index komposit: semua yang dipakai di dokumen ini. MongoDB/NoSQL tidak cocok untuk data uang                                                 | Tidak ada alasan dalam skala rumah tangga                                                     |
| Queue, cache, session: driver `database`                       | Pas                               | Beban satu keluarga. Job-nya cuma email undangan, cek budget, parse import. Satu worker + satu scheduler cukup                                                                                                                                                     | Kalau job menunggu > beberapa detik di antrean, atau lock cache jadi bottleneck (ukur dulu)   |
| Sanctum (cookie web + token mobile)                            | Pas                               | Dua jenis klien butuh dua mode auth, dan Sanctum menyediakan keduanya. Passport/OAuth2 overkill, JWT buatan sendiri tidak perlu                                                                                                                                    | Kalau ada klien pihak ketiga (tidak ada di scope)                                             |
| React + Vite + TanStack Query + shadcn/ui + Recharts           | Pas                               | React dipilih (bukan Vue) supaya `packages/shared` (zod schema + `money.ts`) bisa dipakai web **dan** React Native. TanStack Query memang dibutuhkan: invalidasi saldo/budget setelah tiap transaksi. Tidak perlu Redux/Zustand karena state server dikelola Query | Tidak ada                                                                                     |
| React Native (Expo) + `expo-sqlite`                            | Pas, satu-satunya yang agak berat | Mobile offline itu requirement. Antrean harus tahan crash dan berurutan, jadi SQLite lebih tepat daripada array JSON di AsyncStorage/MMKV (tidak atomik). Scope mobile tetap kecil: 4 layar, create-only                                                           | Kalau antrean ternyata cuma butuh < 20 item dan tidak perlu cache wallet/kategori, MMKV cukup |
| Docker Compose: `app`, `worker`, `scheduler`, `nginx`, `mysql` | Pas                               | 5 container ringan. `worker` dan `scheduler` wajib karena queue `database` butuh proses `queue:work`, dan jadwal butuh `schedule:work`. Mailpit hanya di profil dev                                                                                                | Tidak ada                                                                                     |
| pnpm monorepo (`api`, `web`, `mobile`, `shared`)               | Pas, ada catatan                  | Satu repo untuk satu cerita portofolio, dan `packages/shared` dipakai dua klien. Catatan: Metro (Expo) sering bermasalah dengan symlink pnpm, set `node-linker=hoisted` di `.npmrc` dan atur `metro.config.js` untuk `watchFolders`                                | Kalau bundling mobile terus rewel, pindahkan `shared` jadi salinan yang di-sync skrip         |

**Sengaja tidak dipakai:** Redis, tabel rollup laporan, Octane/Horizon, RabbitMQ, event sourcing/CQRS, microservice, Passport, state manager global di frontend. Tiap keputusan "tidak" ini punya ADR dan syarat kapan dibalik, itu bagian dari cerita portofolionya.

### Pemangkasan scope hasil review

Bukan di level stack, tapi di level dokumen. Beberapa bagian draft sebelumnya lebih berat dari kebutuhan:

| Draft sebelumnya                                                                                     | Sekarang                                                                                                                                                   | Kenapa                                                                                                                                                                                         |
| ---------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tabel `idempotency_keys` + middleware + response disimpan + prune 30 hari + `409` kalau payload beda | Kolom `transactions.client_id` (UUID dari klien) dengan `UNIQUE (household_id, client_id)`. Retry dengan `client_id` sama → balas transaksi yang sudah ada | Hasil akhirnya sama (retry tidak bikin dobel), tapi tanpa tabel, middleware, dan job tambahan. Yang hilang cuma deteksi payload beda, dan itu tidak relevan karena `client_id` dibuat per item |
| Parser BCA + Mandiri + Generic                                                                       | Generic (pemetaan kolom) + **satu parser bank yang benar-benar kamu pakai**                                                                                | Format CSV bank hanya bisa diuji dengan data asli. Parser bank yang tidak kamu pakai tidak bisa diverifikasi                                                                                   |
| `category_rules` (kata kunci → kategori) sebagai bagian wajib import                                 | Stretch goal di minggu 6                                                                                                                                   | Import sudah utuh tanpa ini; baru dikerjakan kalau waktu sisa                                                                                                                                  |
| Playwright e2e beberapa alur                                                                         | Satu alur: catat → budget naik → laporan berubah                                                                                                           | Cukup sebagai bukti, sisanya sudah ditutup test Pest                                                                                                                                           |

---

## Ringkasan Timeline

| Minggu | Fokus                                                             | Output utama                                             |
| ------ | ----------------------------------------------------------------- | -------------------------------------------------------- |
| 1      | Fondasi: Docker, auth ganda, household, undangan, `Money`         | Register → household → undang anggota jalan              |
| 2      | Wallet, transaksi, transfer, saldo atomik, reconcile, `client_id` | Saldo selalu = saldo awal + SUM transaksi, terbukti test |
| 3      | Kategori, budget, alert 80% / 100%, Policy wallet privat          | Alert sekali per ambang, wallet privat tidak bocor       |
| 4      | Web inti (React + shadcn/ui) → **mulai dogfooding**               | Keluarga mulai mencatat transaksi asli                   |
| 5      | Transaksi berulang + scheduler idempoten                          | Gaji/cicilan/listrik ter-generate, scheduler 2x tetap 1  |
| 6      | Import CSV mutasi bank + dedupe hash                              | Import file yang sama 2x → 0 baris baru                  |
| 7      | Laporan + export CSV + seed 200 ribu + `EXPLAIN`                  | Laporan < 100 ms tanpa rollup/cache, ada angkanya        |
| 8      | Mobile Expo: quick-add, daftar, antrean offline                   | Offline → online, retry tidak bikin duplikat             |
| 9      | Hardening, test, docs, ADR, README                                | README dengan cerita nyata, angka, screenshot            |

---

## Keputusan Desain Inti (baca dulu sebelum mulai)

**1. Uang = integer rupiah, lewat satu helper.**

- Kolom `BIGINT` signed, satuan rupiah (IDR tidak punya sen dalam praktik).
- `App\Support\Money` satu-satunya pintu: `Money::parse('Rp1.250.000')` → `1250000`, `Money::format(1250000)` → `Rp1.250.000`, `Money::percentOf()` dengan integer.
- Parsing input pakai string (hapus pemisah ribuan `.`), **tidak pernah** `floatval` / `parseFloat`. Ingat format Indonesia: titik = ribuan, koma = desimal. `1.250` itu seribu dua ratus lima puluh, bukan 1,25. Input `,00` boleh (dibuang), `,50` ditolak.
- Perbandingan persen tanpa pembagian: `spent * 100 >= limit * 80`.
- API mengirim angka sebagai integer JSON (aman di JS sampai 9 kuadriliun). Format tampilan di klien lewat `packages/shared/money.ts`, dipakai web dan mobile.

**2. Tanda (sign) amount mengikuti sudut pandang wallet.**

- `income` → positif, `expense` → negatif, `transfer` → keluar negatif / masuk positif, `adjustment` → bebas.
- Dengan begitu invariant saldo cukup satu rumus: `balance = opening_balance + SUM(amount) WHERE status = 'posted' AND deleted_at IS NULL`.
- `CHECK` constraint di DB menegakkan tanda per tipe (MySQL 8.0.16+ sudah menegakkan CHECK).

**3. Saldo = kolom denormalisasi + reconcile.**

- `wallets.balance` diubah di **transaksi DB yang sama** dengan insert/update/delete transaksi, setelah `lockForUpdate()` pada wallet.
- Transfer mengunci dua wallet **berurutan berdasarkan id** supaya dua transfer berlawanan arah tidak deadlock.
- Update transaksi = balikkan efek lama, terapkan efek baru (bisa pindah wallet → kunci dua wallet).
- Delete = soft delete + balikkan efek.
- `wallets:reconcile` membandingkan `balance` dengan rumus di #2, laporkan selisih, `--fix` untuk membetulkan. Dijadwalkan harian; selisih ≠ 0 = bug, kirim notifikasi ke owner.
- ADR-002: kenapa tidak hitung saldo on-the-fly (list wallet dipanggil di setiap layar, mobile butuh angka cepat) dan kenapa tetap butuh reconcile (bukti, bukan harapan).

**4. Transfer = 2 baris berpasangan.**

- Satu `transfer_group_id` (UUID), baris keluar di wallet asal (negatif), baris masuk di wallet tujuan (positif), `category_id` NULL.
- Edit/hapus transfer selalu menyentuh kedua baris dalam satu transaksi DB.
- Laporan pemasukan/pengeluaran memfilter `type IN ('income','expense')`, jadi transfer dan adjustment otomatis keluar dari laporan.

**5. Household, peran, dan wallet privat.**

- User bisa anggota beberapa household (pivot `household_members`, peran `owner` / `member`). Semua route di bawah `/api/households/{household}/...` dengan scoped binding.
- Wallet `shared` terlihat semua anggota; wallet `private` hanya terlihat pemiliknya, **termasuk** dari owner household. Ini keputusan privasi, tulis di ADR-005.
- Laporan, export, pencarian, dan budget memakai satu sumber kebenaran: `VisibleWallets::for($user, $household)` → daftar id wallet. Semua query transaksi wajib lewat ini. Ini tempat paling gampang bocor; ada test khusus di setiap endpoint.
- Budget household hanya menghitung transaksi dari wallet `shared` (budget itu milik bersama). Wallet privat tidak masuk budget.

| Aksi                                               | Owner | Member              |
| -------------------------------------------------- | ----- | ------------------- |
| Undang / keluarkan anggota, ubah peran             | ✅    | ❌                  |
| Buat / arsip wallet shared                         | ✅    | ❌                  |
| Buat wallet private milik sendiri                  | ✅    | ✅                  |
| Catat transaksi di wallet shared                   | ✅    | ✅                  |
| Edit / hapus transaksi orang lain di wallet shared | ✅    | ❌ (hanya miliknya) |
| Kelola kategori, budget, transaksi berulang shared | ✅    | ❌ (lihat saja)     |
| Lihat wallet private orang lain                    | ❌    | ❌                  |

**6. Transaksi berulang idempoten lewat tabel occurrence.**

- Kunci idempotensinya `UNIQUE (rule_id, due_on)` di tabel `recurring_occurrences`, bukan di `transactions`. Alasannya: rule bertipe transfer menghasilkan 2 baris transaksi untuk satu jatuh tempo, jadi unique di `transactions` akan bentrok.
- Alur per jatuh tempo, dalam satu transaksi DB: insert occurrence (konflik → sudah diproses, skip) → buat transaksi → update saldo → majukan `next_due_on`.
- Catch-up: kalau scheduler mati 3 hari, command memproses semua jatuh tempo yang tertinggal (batas aman 31 iterasi per rule).
- Tanggal dihitung dari **anchor** (`day_of_month`), bukan dari tanggal sebelumnya. Rule tanggal 31: Jan 31 → Feb 28/29 → **Mar 31** (bukan Mar 28). Bug drift ini klasik, wajib di-test.
- Tagihan yang nominalnya berubah (listrik, air) pakai `requires_confirmation = true` → transaksi dibuat `status = pending`, tidak memengaruhi saldo, laporan, maupun budget sampai dikonfirmasi (dan nominalnya boleh diubah saat konfirmasi).
- Scheduler: `dailyAt('00:10')`, timezone household (default `Asia/Jakarta`), `withoutOverlapping()->onOneServer()` (lock lewat cache `database`). Lock itu untuk efisiensi; jaminan sebenarnya tetap unique constraint.

**7. Tanggal transaksi = `DATE` lokal, bukan timestamp.**
`occurred_on` bertipe `DATE` dalam timezone household. Belanja jam 23.30 WIB itu transaksi hari itu, bukan besok (yang terjadi kalau disimpan UTC lalu dipotong tanggalnya). `created_at` tetap timestamp UTC untuk audit.

**8. Budget dan alert.**

- Budget per kategori per bulan (`month` = tanggal 1). Tombol "salin dari bulan lalu".
- Terpakai dihitung langsung dari transaksi (query kecil, ber-index), tidak disimpan.
- Setelah transaksi expense commit (`afterCommit`), job `CheckBudgetThresholds` menghitung ulang kategori + bulan transaksi itu (termasuk transaksi yang di-backdate ke bulan lalu).
- Ambang 80 dan 100: insert `budget_alerts` dengan `UNIQUE (budget_id, threshold)`. Konflik = sudah pernah alert, jangan kirim lagi. Hasilnya tidak ada spam notifikasi.
- Notifikasi: `database` (lonceng di web) + email (Mailpit di dev).

**9. Import CSV dengan dedupe berbasis hash.**

- Upload → `imports` + file → job parse → baris di `import_rows` (staging) → preview di web → user petakan kategori → commit.
- Parser di belakang interface `StatementParser`: `GenericCsvParser` (pemetaan kolom, preset disimpan) + **satu parser untuk bank yang kamu pakai**. Format CSV bank hanya bisa diuji dengan data asli, jadi parser bank lain masuk "next steps". Hati-hati format bank: suffix `DB`/`CR`, pemisah ribuan, tanggal tanpa tahun, encoding bukan UTF-8.
- `fingerprint = sha256(wallet_id | tanggal | amount | deskripsi_dinormalisasi | occurrence_index)`.
  - `occurrence_index` = urutan kemunculan baris identik (tanggal + amount + deskripsi sama) di file itu. Tanpa ini, dua kali beli kopi Rp25.000 di hari yang sama dianggap duplikat dan salah satunya hilang.
  - Deskripsi dinormalisasi: huruf kecil, spasi rapat, buang nomor referensi yang berubah-ubah.
- `transactions.import_hash` dengan `UNIQUE (wallet_id, import_hash)`. Import ulang file yang sama atau file yang periodenya tumpang tindih → baris lama ditandai `duplicate`, tidak di-insert.
- Batasan jujur (ADR-006): dedupe akurat kalau file memuat hari yang **utuh**. Transaksi yang dicatat manual lalu muncul lagi di CSV tidak otomatis terdeteksi; preview menandai kandidat (tanggal ±1 hari + amount sama) supaya user memilih.

**10. Laporan tanpa rollup, dibuktikan dengan angka.**

- Semua laporan dihitung langsung dari `transactions` dengan index `(household_id, occurred_on)`.
- Daftar wallet terlihat diambil dulu (jumlahnya kecil), lalu masuk sebagai `wallet_id IN (...)`, jadi tidak perlu join.
- Bukti di minggu 7: seed 200 ribu transaksi untuk household demo **plus ~1 juta transaksi noise di ratusan household lain**. Tanpa noise, index `household_id` tidak diuji sama sekali dan angkanya menipu.
- Ukur: `EXPLAIN` / `EXPLAIN ANALYZE` sebelum vs sesudah index, waktu tiap query (p50/p95 dari N run). Eksperimen tambahan (opsional): covering index `(household_id, occurred_on, type, wallet_id, category_id, amount)`. Pakai hanya kalau angkanya membenarkan, dan tulis hasilnya apa pun itu.
- Kalau suatu saat lambat, urutan solusinya tercatat di ADR-007: index → covering index → summary table bulanan → cache. Redis paling akhir.

**11. Idempotensi tulis lewat `client_id`.**

- Klien (web dan mobile) membuat UUID `client_id` **per item yang akan dikirim** dan menyertakannya di body `POST` transaksi dan transfer. Web membuatnya per submit form (double click aman), mobile membuatnya saat item masuk antrean dan menyimpannya bersama item.
- Server: `transactions.client_id` dengan `UNIQUE (household_id, client_id)`. Di dalam Action, **setelah** wallet dikunci (`lockForUpdate`), cek dulu apakah `client_id` itu sudah ada. Kalau sudah, balas transaksi yang ada (status `200`, bukan `201`) tanpa efek apa pun. Karena wallet sudah dikunci, dua retry bersamaan tersusun serial: yang kedua pasti melihat hasil yang pertama. Unique index tetap jadi jaring terakhir.
- Transfer: `client_id` dipasang di baris **keluar**, baris masuk NULL (MySQL mengizinkan banyak NULL di unique index).
- Transaksi dari scheduler dan import tidak punya `client_id` (NULL); idempotensi mereka lewat `recurring_occurrences` dan `import_hash`.
- Batasan jujur (ADR-010): retry dengan `client_id` sama tapi isi berbeda dibalas transaksi lama tanpa peringatan. Tidak masalah karena `client_id` dibuat per item, dan ini menghemat tabel, middleware, dan job prune.

**12. Mobile offline: create-only.**

- Offline yang didukung hanya **membuat** transaksi (quick-add). Edit dan hapus wajib online. Ini menghindari konflik edit tanpa perlu sync engine dua arah. ADR-008.
- `occurred_on` diambil saat user mencatat, bukan saat sync.
- Antrean di `expo-sqlite`, dikirim berurutan saat online, retry dengan backoff memakai `client_id` yang sama.

**13. Tanpa Redis.** Queue `database`, cache `database`, session `database`. Satu worker dan satu scheduler cukup untuk beban rumah tangga. Redis baru dipertimbangkan kalau ada angka (antrean job menumpuk, query cache lock lambat). ADR-009.

---

## Minggu 1 — Fondasi

- Monorepo pnpm: `apps/api` (Laravel), `apps/web`, `apps/mobile`, `packages/shared`. Sejak awal tambahkan `.npmrc` dengan `node-linker=hoisted` supaya Metro (Expo) tidak bermasalah dengan symlink pnpm.
- `docker-compose.yml`: `app` (php-fpm), `nginx`, `mysql`, plus `worker` (`queue:work`) dan `scheduler` (`schedule:work`) dari image yang sama. `mailpit` hanya di profil dev.
- Nginx satu origin: `/` → build web statis, `/api` dan `/sanctum` → Laravel. Cookie Sanctum jadi tidak ribet.
- Laravel 13 + Pest + Larastan + Pint. Migration default (`users`, `sessions`, `cache`, `jobs`, `notifications`, `personal_access_tokens`).
- Auth:
  - Web: Sanctum SPA (`/sanctum/csrf-cookie`, `POST /api/auth/login`, `/logout`, `/register`).
  - Mobile: `POST /api/auth/token` (email, password, `device_name`) → personal access token; `DELETE /api/auth/token` revoke.
  - Rate limit login.
- Household:
  - Register → otomatis buat household pertama + user jadi `owner` (satu transaksi DB) + seed kategori default (Makan, Transport, Listrik, Gaji, ...).
  - `POST /households/{h}/invitations` (owner): token acak, disimpan **hash**-nya, kedaluwarsa 7 hari, terikat email. Email lewat queue.
  - `POST /invitations/{token}/accept`: wajib login dengan email yang sama, token belum dipakai/expired/dicabut.
  - Keluarkan anggota, ubah peran (owner terakhir tidak boleh turun peran).
- `Money` + unit test lengkap (parse berbagai format, tolak desimal non-nol, format, persen).
- Enum PHP: `HouseholdRole`, `WalletType`, `WalletVisibility`, `TransactionType`, `TransactionStatus`, `Frequency`.

**Done kalau:** register → household + kategori default terbentuk; undang email kedua → accept → jadi member; token yang sama tidak bisa dipakai dua kali; login token dari curl bisa memanggil `GET /api/households`.

## Minggu 2 — Wallet, Transaksi, Saldo

- Wallet: CRUD, `opening_balance` saat dibuat, arsip (bukan hapus kalau sudah ada transaksi).
- Actions (bukan logika di controller): `CreateTransaction`, `UpdateTransaction`, `DeleteTransaction`, `CreateTransfer`, `UpdateTransfer`, `DeleteTransfer`, `AdjustBalance`.

  ```php
  DB::transaction(function () use ($data) {
      $wallet = Wallet::whereKey($data->walletId)->lockForUpdate()->firstOrFail();

      // idempotensi (#11): setelah wallet terkunci, retry paralel jadi serial
      if ($existing = Transaction::where('household_id', $data->householdId)
              ->where('client_id', $data->clientId)->first()) {
          return [$existing, false];            // controller balas 200, bukan 201
      }

      $tx = Transaction::create([...]);          // amount sudah ber-tanda
      $wallet->increment('balance', $tx->amount); // di transaksi yang sama
      return [$tx, true];
  });

  // transfer: kunci dua wallet berurutan id → tidak deadlock
  $wallets = Wallet::whereKey([$fromId, $toId])->orderBy('id')->lockForUpdate()->get();
  ```

- `AdjustBalance`: user memasukkan saldo riil → sistem membuat transaksi `adjustment` sebesar selisihnya. Tidak pernah mengubah `balance` langsung.
- `client_id` wajib di body `POST` transaksi dan transfer (zod di `packages/shared`, Form Request di Laravel).
- `GET /transactions`: filter wallet, kategori, tipe, rentang tanggal, pencarian catatan; **cursor pagination** (`occurred_on DESC, id DESC`).
- Command `wallets:reconcile {--household=} {--fix}` + jadwal harian.
- Test Pest:
  - Create / update (ubah amount, ubah wallet) / delete / transfer / edit transfer / hapus transfer → `wallets:reconcile` selisih 0 di semua skenario.
  - Rusak `balance` manual di DB → reconcile mendeteksi; `--fix` membetulkan.
  - `client_id` sama 2x (berurutan dan paralel lewat `Process::pool`) → 1 transaksi, saldo berubah sekali, response kedua `200`.
  - CHECK constraint menolak expense dengan amount positif (insert langsung lewat query builder).
- `load-tests/concurrent-writes.js` (k6): 50 VU menulis ke wallet yang sama, sebagian dengan `client_id` duplikat. Setelah selesai, reconcile harus 0.

**Done kalau:** semua test di atas hijau dan k6 + reconcile menunjukkan saldo tidak meleset walau ditulis paralel.

## Minggu 3 — Kategori, Budget, Policy

- Kategori: CRUD per household, `kind` income/expense, warna + ikon, arsip (transaksi lama tetap menunjuk kategori yang diarsip).
- Policy: `HouseholdPolicy`, `WalletPolicy`, `TransactionPolicy`, `CategoryPolicy`, `BudgetPolicy` sesuai tabel di #5. `VisibleWallets` service dipakai semua query transaksi.
- Budget: `PUT /budgets/{month}` (batch set limit per kategori), `POST /budgets/{month}/copy-from-previous`, `GET /budgets/{month}` → limit, terpakai, sisa, persen.
- Job `CheckBudgetThresholds` (dispatch `afterCommit`) + `budget_alerts` unik + notifikasi `BudgetThresholdReached` (database + mail).
- Test:
  - 79% → tidak ada alert; tembus 80% → 1 alert; transaksi berikutnya masih di 80–99% → tetap 1 alert; tembus 100% → alert kedua.
  - Transaksi di wallet privat tidak menggerakkan budget.
  - Member B tidak melihat wallet privat A di: daftar wallet, daftar transaksi, detail transaksi (403), pencarian.
  - Member tidak bisa mengubah budget; owner bisa.

**Done kalau:** test alert dan test privasi hijau.

## Minggu 4 — Web Inti + Mulai Dogfooding

Tujuannya bukan web yang lengkap, tapi cukup untuk **dipakai keluarga mulai minggu ini**. Semakin awal dipakai, semakin banyak cerita nyata untuk README.

- Stack: React + Vite + TypeScript, React Router, TanStack Query, shadcn/ui, `react-hook-form` + `zod` (schema dari `packages/shared`), Recharts (minggu 7).
- `api/client.ts`: axios dengan `withCredentials`, ambil CSRF cookie sekali, interceptor 401 → login, 403 → toast, 422 → pesan form. Form transaksi membuat `client_id` baru **per submit** (via `newClientId()` dari `packages/shared`), bukan per klik, jadi double click aman.
- `MoneyInput`: tampil `1.250.000` saat diketik, nilai form tetap integer. Tidak ada `parseFloat` di seluruh codebase (pasang aturan lint).
- Halaman: login/register, onboarding household, pemilih household, dashboard (total saldo per wallet, progres budget bulan ini), transaksi (tabel + filter + dialog tambah/edit, transfer), wallet (tambah, sesuaikan saldo, arsip), kategori, budget bulanan, anggota & undangan, lonceng notifikasi.
- Setelah bayar/transfer: invalidasi query `wallets`, `transactions`, `budgets`.
- Deploy minimal ke VPS (atau jalan di laptop yang bisa diakses keluarga di LAN) supaya bisa dipakai beneran. Backup MySQL harian (`mysqldump` via cron) sejak hari pertama data asli masuk.
- Buat `docs/dogfooding-log.md`: catat setiap hal yang mengganggu saat dipakai. Ini bahan README dan prioritas minggu berikutnya.

**Done kalau:** kamu dan pasangan mencatat pengeluaran asli selama seminggu lewat web, dan reconcile harian tetap 0.

## Minggu 5 — Transaksi Berulang

- `recurring_rules` CRUD: tipe income/expense/transfer, frekuensi weekly/monthly/yearly, `interval_count`, `day_of_month`, `start_on`, `end_on`, `requires_confirmation`.
- `RecurrenceCalculator` murni (tanpa DB) + unit test: akhir bulan (31 → Feb → Mar 31), tahun kabisat, interval 2 bulan, weekly, `end_on`.
- Command `recurring:post-due` (#6), dijadwalkan harian. Opsi `--date=` untuk test dan backfill.
- Transaksi `pending`: daftar "perlu konfirmasi" di dashboard, aksi konfirmasi (boleh ubah amount) → `status = posted` + update saldo + cek budget, atau lewati.
- Test:
  - Command jalan 2x (berurutan dan paralel lewat `Process::pool`) → 1 occurrence, 1 transaksi (atau 2 baris untuk transfer), saldo benar.
  - Scheduler mati 3 hari → command berikutnya membuat 3 jatuh tempo yang tertinggal.
  - Rule `requires_confirmation` → pending tidak mengubah saldo, budget, maupun laporan sampai dikonfirmasi.
  - Rule diubah/dinonaktifkan → occurrence lama tidak ikut berubah.
- Pakai untuk data asli: gaji, cicilan, listrik (pending), tabungan bulanan (transfer).

**Done kalau:** test hijau, dan di awal bulan transaksi berulang keluargamu muncul sendiri.

## Minggu 6 — Import CSV Mutasi Bank

- `POST /imports` (upload, pilih wallet + format) → job `ParseImport` → `import_rows` dengan status `new` / `duplicate` / `invalid`.
- Parser: `GenericCsvParser` + parser untuk bank yang kamu pakai (#9). Deteksi encoding (`mb_detect_encoding` → konversi ke UTF-8), BOM, delimiter `,`/`;`.
- `FingerprintCalculator` (#9) + `DescriptionNormalizer` + unit test.
- `POST /imports/{id}/commit`: baris `new` yang dicentang → transaksi dalam batch (chunk 500 per transaksi DB), saldo diupdate sekali per batch dengan `lockForUpdate`. Insert pakai `insertOrIgnore` pada `UNIQUE (wallet_id, import_hash)` sebagai jaring kedua.
- Web: wizard upload → preview (tabel, badge duplikat/kandidat duplikat manual, pilih kategori) → commit → ringkasan.
- **Stretch goal:** `category_rules` sederhana (kata kunci di deskripsi → kategori, mis. "PLN" → Listrik), dibuat dari koreksi user ("ingat pilihan ini"). Kerjakan hanya kalau waktu sisa.
- Fixture CSV anonim di `tests/Fixtures/statements/` (jangan commit mutasi asli).
- Test: file sama 2x → impor kedua 0 baris baru; dua file tumpang tindih 10 hari → tidak ada dobel; dua baris identik sah di satu file → keduanya masuk; commit di-retry setelah gagal di tengah → tidak dobel; reconcile 0.

**Done kalau:** mutasi rekening asli sebulan bisa diimpor, dan mengimpor ulang tidak menambah apa pun.

## Minggu 7 — Laporan, Export, Bukti Performa

**API laporan** (semua lewat `VisibleWallets`, hanya `status = posted`, tanpa transfer/adjustment kecuali cash flow):

- `GET /reports/summary?month=` → pemasukan, pengeluaran, net, perbandingan bulan lalu.
- `GET /reports/by-category?from&to&kind=expense` → total per kategori + persen.
- `GET /reports/trend?months=6` → pemasukan vs pengeluaran per bulan.
- `GET /reports/cash-flow?from&to&wallet=` → net harian + saldo berjalan (window function, dimulai dari saldo sebelum `from`).
- `GET /reports/export.csv?from&to` → streaming (`streamDownload` + `lazyById`), UTF-8 dengan BOM supaya Excel benar, delimiter bisa dipilih (`;` untuk Excel locale Indonesia).

Contoh query:

```sql
-- tren 6 bulan
SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym,
       SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END)  AS income,
      -SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END)  AS expense
  FROM transactions
 WHERE household_id = ?
   AND occurred_on >= ? AND occurred_on < ?
   AND wallet_id IN (/* VisibleWallets */)
   AND type IN ('income', 'expense')
   AND status = 'posted'
   AND deleted_at IS NULL
 GROUP BY ym
 ORDER BY ym;

-- cash flow dengan saldo berjalan
SELECT occurred_on,
       SUM(amount) AS net,
       ? + SUM(SUM(amount)) OVER (ORDER BY occurred_on) AS running_balance  -- ? = saldo sebelum `from`
  FROM transactions
 WHERE household_id = ? AND occurred_on BETWEEN ? AND ?
   AND wallet_id IN (...) AND status = 'posted' AND deleted_at IS NULL
 GROUP BY occurred_on;
```

**Bukti performa:**

- `php artisan demo:seed-large`: household demo 200 ribu transaksi (3 tahun, 6 wallet, distribusi realistis: banyak expense kecil, sedikit income besar) + ~1 juta transaksi di 500 household lain. Insert batch 5.000 baris per statement, bukan factory satu per satu.
- `php artisan reports:benchmark`: jalankan tiap query laporan 50x, catat p50/p95, simpan ke `docs/benchmarks/reports.md` bersama `EXPLAIN ANALYZE`.
- Tabel perbandingan: tanpa index (full scan) → `(household_id, occurred_on)` → covering index (opsional). Target hipotesis: semua laporan < 100 ms p95 di laptop. Sesuaikan dengan hasil ukur, tulis spesifikasi mesin dan versi MySQL.

**Web:** halaman laporan dengan tab Ringkasan, Kategori (donut), Tren (bar income vs expense), Cash flow (line saldo berjalan), tombol Export. Recharts default, tanpa polesan berlebihan.

**Done kalau:** angka laporan cocok dengan fixture yang dihitung manual, member B tidak bisa melihat angka wallet privat A di laporan maupun export, dan `docs/benchmarks/reports.md` berisi angka + `EXPLAIN`.

## Minggu 8 — Mobile (Expo)

- Expo Router + TypeScript, TanStack Query, `expo-secure-store` (token), `expo-sqlite` (antrean + cache), `@react-native-community/netinfo`, `expo-crypto` (`randomUUID`).
- Layar:
  - Login + pilih household.
  - **Quick-add**: keypad nominal besar, grid kategori (yang paling sering di atas), chip wallet, tipe expense default, tanggal default hari ini. Target: catat pengeluaran < 5 detik.
  - Daftar transaksi (online: dari API; offline: cache terakhir + item antrean ditandai "belum terkirim").
  - Status sinkronisasi: jumlah pending, daftar gagal + alasan, tombol coba lagi / hapus.
- Cache offline untuk wallet dan kategori (supaya quick-add jalan tanpa sinyal).
- Antrean offline (`client_id` dibuat saat item masuk antrean dan **tidak pernah diganti**):

| Status     | Arti                                                                  |
| ---------- | --------------------------------------------------------------------- |
| `pending`  | dibuat lokal, belum dikirim                                           |
| `sending`  | sedang dikirim                                                        |
| `synced`   | server balas 201, atau 200 (item sudah ada dari percobaan sebelumnya) |
| `retrying` | network error / 5xx → backoff 2s, 10s, 1m, 5m, `client_id` sama       |
| `failed`   | 422 / 403 → butuh tindakan user (mis. wallet sudah diarsip)           |

- Flush berurutan saat online, saat app kembali ke foreground, dan setelah item baru masuk.
- Tampilan saldo offline = saldo terakhir dari server + efek item pending, dengan penanda "estimasi".
- Test: unit test sync engine (mock fetch: sukses, timeout, response hilang lalu retry, 422). Manual: mode pesawat → catat 5 transaksi → online → 5 transaksi di server, reconcile 0. Matikan jaringan **setelah** request terkirim tapi sebelum response diterima → retry → tetap 1 transaksi.
- Build APK internal (EAS) untuk dipakai sendiri dan pasangan.

**Done kalau:** skenario mode pesawat dan "response hilang" tidak menghasilkan duplikat, dan quick-add dipakai harian di HP.

## Minggu 9 — Hardening & Presentasi

- Test: lengkapi yang belum, CI GitHub Actions (Pest + Larastan + Pint + build web + lint mobile), `php artisan test --parallel`. Playwright cukup **satu alur**: catat transaksi → budget naik → laporan berubah.
- Keamanan: semua route di bawah household lewat Policy, validasi Form Request, rate limit login & import, ukuran upload maksimum, file import disimpan di disk privat dan dihapus setelah commit, header keamanan di Nginx, secret via env.
- Operasional: backup MySQL harian + uji restore sekali, `wallets:reconcile` harian dengan notifikasi, `/up` health check, log terstruktur (JSON) dengan `household_id` dan `request_id`.
- Docs (bagian yang dilihat recruiter pertama):
  - README: problem (keuangan keluarga tercecer di catatan, mutasi bank, dan ingatan), **cerita nyata** (sejak kapan dipakai, berapa transaksi, bug nyata dari `dogfooding-log.md` dan cara memperbaikinya), screenshot web + GIF quick-add mobile, diagram alur, `docker compose up`, `make demo`.
  - Bagian "Kenapa stack ini": tabel hasil review stack di atas (apa yang dipakai, apa yang sengaja tidak, kapan dibalik).
  - Tabel "Poin teknis" dengan link ke test yang membuktikan masing-masing.
  - Tabel angka laporan + `EXPLAIN` dari minggu 7.
  - `docs/adr/`: 001 uang integer, 002 saldo denormalisasi + reconcile, 003 transfer dua baris, 004 idempotensi transaksi berulang, 005 wallet privat & visibilitas laporan, 006 dedupe import, 007 laporan tanpa rollup/cache, 008 mobile offline create-only, 009 database queue tanpa Redis, 010 idempotensi tulis lewat `client_id`.
  - "Known limitations & what I'd do next": multi-currency, edit offline + sync dua arah, parser bank tambahan, sinkronisasi bank otomatis, OCR struk, split bill antar anggota, tujuan tabungan, re-arm alert budget saat transaksi dihapus, deteksi payload beda pada retry `client_id`.
- Screenshot pakai data seed, **bukan** data keluarga asli.

---

## Schema Database (MySQL 8)

Ditulis sebagai SQL supaya ringkas; implementasinya Laravel migration (`$table->...`), CHECK constraint lewat `DB::statement()`. Semua uang `BIGINT` rupiah. Tabel bawaan Laravel (`users`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications`, `personal_access_tokens`, `password_reset_tokens`) tidak ditulis ulang.

```sql
-- ---------- Household & anggota ----------

CREATE TABLE households (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  currency    CHAR(3)      NOT NULL DEFAULT 'IDR',
  timezone    VARCHAR(40)  NOT NULL DEFAULT 'Asia/Jakarta',
  created_by  BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE household_members (
  household_id BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  role         ENUM('owner','member') NOT NULL DEFAULT 'member',
  joined_at    TIMESTAMP NOT NULL,
  PRIMARY KEY (household_id, user_id),
  KEY idx_members_user (user_id),
  FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE
);

CREATE TABLE household_invitations (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  email        VARCHAR(255) NOT NULL,
  role         ENUM('owner','member') NOT NULL DEFAULT 'member',
  token_hash   CHAR(64) NOT NULL,                 -- sha256(token), token asli hanya di email
  invited_by   BIGINT UNSIGNED NOT NULL,
  expires_at   TIMESTAMP NOT NULL,
  accepted_at  TIMESTAMP NULL,
  revoked_at   TIMESTAMP NULL,
  created_at   TIMESTAMP NULL,
  UNIQUE KEY uq_invitation_token (token_hash),
  KEY idx_invitation_household_email (household_id, email),
  FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
);

-- ---------- Wallet & kategori ----------

CREATE TABLE wallets (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id    BIGINT UNSIGNED NOT NULL,
  owner_user_id   BIGINT UNSIGNED NULL,           -- wajib diisi kalau private
  visibility      ENUM('shared','private') NOT NULL DEFAULT 'shared',
  type            ENUM('cash','bank','ewallet') NOT NULL,
  name            VARCHAR(100) NOT NULL,
  opening_balance BIGINT NOT NULL DEFAULT 0,
  balance         BIGINT NOT NULL DEFAULT 0,      -- denormalisasi, dijaga reconcile
  archived_at     TIMESTAMP NULL,
  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,
  KEY idx_wallets_household (household_id, visibility),
  FOREIGN KEY (household_id)  REFERENCES households(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT chk_private_has_owner CHECK (visibility = 'shared' OR owner_user_id IS NOT NULL)
);

CREATE TABLE categories (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  kind         ENUM('income','expense') NOT NULL,
  name         VARCHAR(60) NOT NULL,
  color        VARCHAR(9)  NULL,
  icon         VARCHAR(40) NULL,
  archived_at  TIMESTAMP NULL,
  created_at   TIMESTAMP NULL,
  updated_at   TIMESTAMP NULL,
  UNIQUE KEY uq_category_name (household_id, kind, name),
  FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
);

-- ---------- Transaksi ----------

CREATE TABLE transactions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id      BIGINT UNSIGNED NOT NULL,     -- denormalisasi: filter laporan tanpa join
  wallet_id         BIGINT UNSIGNED NOT NULL,
  category_id       BIGINT UNSIGNED NULL,         -- NULL untuk transfer & adjustment
  type              ENUM('income','expense','transfer','adjustment') NOT NULL,
  status            ENUM('posted','pending') NOT NULL DEFAULT 'posted',
  amount            BIGINT NOT NULL,              -- ber-tanda, sudut pandang wallet
  occurred_on       DATE NOT NULL,                -- tanggal lokal household
  note              VARCHAR(255) NULL,
  transfer_group_id CHAR(36) NULL,                -- pasangan transfer
  client_id         CHAR(36) NULL,                -- idempotensi tulis dari web/mobile (#11)
  recurring_rule_id BIGINT UNSIGNED NULL,
  import_hash       CHAR(64) NULL,                -- fingerprint import CSV
  created_by        BIGINT UNSIGNED NOT NULL,
  updated_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  deleted_at        TIMESTAMP NULL,

  KEY idx_tx_household_date (household_id, occurred_on),   -- laporan & daftar
  KEY idx_tx_wallet_date    (wallet_id, occurred_on),      -- daftar per wallet, reconcile
  KEY idx_tx_category_date  (category_id, occurred_on),    -- hitung budget
  KEY idx_tx_transfer_group (transfer_group_id),
  UNIQUE KEY uq_tx_client   (household_id, client_id),     -- NULL boleh berulang di MySQL
  UNIQUE KEY uq_tx_import   (wallet_id, import_hash),      -- NULL boleh berulang di MySQL

  FOREIGN KEY (household_id) REFERENCES households(id),
  FOREIGN KEY (wallet_id)    REFERENCES wallets(id),
  FOREIGN KEY (category_id)  REFERENCES categories(id),
  FOREIGN KEY (created_by)   REFERENCES users(id),

  CONSTRAINT chk_tx_sign CHECK (
       (type = 'income'     AND amount > 0)
    OR (type = 'expense'    AND amount < 0)
    OR (type = 'transfer'   AND amount <> 0 AND transfer_group_id IS NOT NULL)
    OR (type = 'adjustment' AND amount <> 0)
  ),
  CONSTRAINT chk_tx_category CHECK (
    type IN ('income','expense') OR category_id IS NULL
  )
);

-- ---------- Budget ----------

CREATE TABLE budgets (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  category_id  BIGINT UNSIGNED NOT NULL,
  month        DATE NOT NULL,                     -- selalu tanggal 1
  limit_amount BIGINT NOT NULL,
  created_at   TIMESTAMP NULL,
  updated_at   TIMESTAMP NULL,
  UNIQUE KEY uq_budget (household_id, category_id, month),
  FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id)  REFERENCES categories(id),
  CONSTRAINT chk_budget_positive CHECK (limit_amount > 0),
  CONSTRAINT chk_budget_month CHECK (DAY(month) = 1)
);

CREATE TABLE budget_alerts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  budget_id    BIGINT UNSIGNED NOT NULL,
  threshold    TINYINT UNSIGNED NOT NULL,         -- 80 | 100
  spent_amount BIGINT NOT NULL,                   -- snapshot saat alert
  triggered_at TIMESTAMP NOT NULL,
  UNIQUE KEY uq_budget_alert (budget_id, threshold),   -- tidak ada spam
  FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE
);

-- ---------- Transaksi berulang ----------

CREATE TABLE recurring_rules (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id          BIGINT UNSIGNED NOT NULL,
  wallet_id             BIGINT UNSIGNED NOT NULL,
  to_wallet_id          BIGINT UNSIGNED NULL,      -- untuk transfer
  category_id           BIGINT UNSIGNED NULL,
  type                  ENUM('income','expense','transfer') NOT NULL,
  amount                BIGINT NOT NULL,           -- magnitudo (positif); tanda diberikan saat posting
  note                  VARCHAR(255) NULL,
  frequency             ENUM('weekly','monthly','yearly') NOT NULL,
  interval_count        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  day_of_month          TINYINT UNSIGNED NULL,     -- anchor 1..31 (monthly/yearly)
  day_of_week           TINYINT UNSIGNED NULL,     -- 1..7 (weekly)
  start_on              DATE NOT NULL,
  end_on                DATE NULL,
  next_due_on           DATE NOT NULL,
  requires_confirmation BOOLEAN NOT NULL DEFAULT FALSE,   -- listrik, air → pending
  is_active             BOOLEAN NOT NULL DEFAULT TRUE,
  created_by            BIGINT UNSIGNED NOT NULL,
  created_at            TIMESTAMP NULL,
  updated_at            TIMESTAMP NULL,
  KEY idx_rules_due (is_active, next_due_on),
  FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
  FOREIGN KEY (wallet_id)    REFERENCES wallets(id),
  CONSTRAINT chk_rule_amount CHECK (amount > 0),
  CONSTRAINT chk_rule_transfer CHECK (type <> 'transfer' OR to_wallet_id IS NOT NULL)
);

CREATE TABLE recurring_occurrences (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id        BIGINT UNSIGNED NOT NULL,
  due_on         DATE NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,            -- baris utama (keluar, untuk transfer)
  created_at     TIMESTAMP NOT NULL,
  UNIQUE KEY uq_occurrence (rule_id, due_on),     -- kunci idempotensi scheduler
  FOREIGN KEY (rule_id) REFERENCES recurring_rules(id) ON DELETE CASCADE
);

-- ---------- Import CSV ----------

CREATE TABLE imports (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id      BIGINT UNSIGNED NOT NULL,
  wallet_id         BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  bank_format       VARCHAR(30) NOT NULL,         -- generic | <bank yang dipakai>
  column_mapping    JSON NULL,                    -- untuk generic
  original_filename VARCHAR(255) NOT NULL,
  file_path         VARCHAR(255) NULL,            -- disk privat, dihapus setelah commit
  status            ENUM('pending','parsing','ready','committing','committed','failed') NOT NULL DEFAULT 'pending',
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  new_rows          INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  invalid_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  error             TEXT NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  KEY idx_imports_household (household_id, created_at),
  FOREIGN KEY (wallet_id) REFERENCES wallets(id)
);

CREATE TABLE import_rows (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id      BIGINT UNSIGNED NOT NULL,
  row_number     INT UNSIGNED NOT NULL,
  occurred_on    DATE NULL,
  amount         BIGINT NULL,                     -- sudah ber-tanda
  description    VARCHAR(255) NULL,
  fingerprint    CHAR(64) NULL,
  status         ENUM('new','duplicate','possible_duplicate','invalid','committed','skipped') NOT NULL,
  category_id    BIGINT UNSIGNED NULL,
  transaction_id BIGINT UNSIGNED NULL,
  error          VARCHAR(255) NULL,
  raw            JSON NOT NULL,
  KEY idx_import_rows (import_id, row_number),
  FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE
);

-- STRETCH (minggu 6, hanya kalau waktu sisa)
CREATE TABLE category_rules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  keyword      VARCHAR(100) NOT NULL,             -- dicocokkan ke deskripsi ternormalisasi
  category_id  BIGINT UNSIGNED NOT NULL,
  created_at   TIMESTAMP NULL,
  UNIQUE KEY uq_category_rule (household_id, keyword),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
```

### Query reconcile

```sql
SELECT w.id, w.balance,
       w.opening_balance + COALESCE(SUM(t.amount), 0) AS expected
  FROM wallets w
  LEFT JOIN transactions t
         ON t.wallet_id = w.id AND t.status = 'posted' AND t.deleted_at IS NULL
 WHERE w.household_id = ?
 GROUP BY w.id, w.balance, w.opening_balance
HAVING w.balance <> expected;
```

### Catatan schema

- `household_id` di `transactions` sengaja diduplikasi dari wallet supaya index laporan `(household_id, occurred_on)` bisa dipakai tanpa join. Wallet tidak pernah pindah household, jadi aman.
- Soft delete transaksi + `UNIQUE (wallet_id, import_hash)`: baris yang sengaja dihapus user tidak akan masuk lagi saat import ulang. Itu perilaku yang diinginkan, tulis di ADR-006.
- Tidak ada tabel rollup laporan, tidak ada tabel idempotency, dan tidak ada kolom `spent` di `budgets`. Semuanya dihitung dari `transactions` atau dijaga constraint.
- Ukuran tabel: 200 ribu transaksi × ~150 byte ≈ 30 MB + index. Kecil untuk MySQL; inti buktinya ada di bentuk query dan index, bukan ukuran.

---

## Struktur Folder

```
household-finance/
├─ apps/
│  ├─ api/                                   # Laravel 13
│  │  ├─ app/
│  │  │  ├─ Actions/
│  │  │  │  ├─ Households/                    # CreateHousehold, InviteMember, AcceptInvitation, RemoveMember
│  │  │  │  ├─ Wallets/                       # CreateWallet, AdjustBalance, ArchiveWallet, ReconcileWallets
│  │  │  │  ├─ Transactions/                  # CreateTransaction, UpdateTransaction, DeleteTransaction
│  │  │  │  ├─ Transfers/                     # CreateTransfer, UpdateTransfer, DeleteTransfer
│  │  │  │  ├─ Recurring/                     # PostDueOccurrences, ConfirmPendingTransaction
│  │  │  │  └─ Imports/                       # ParseImport, CommitImport
│  │  │  ├─ Console/Commands/
│  │  │  │  ├─ ReconcileWalletsCommand.php    # wallets:reconcile {--fix}
│  │  │  │  ├─ PostRecurringCommand.php       # recurring:post-due {--date=}
│  │  │  │  ├─ SeedLargeDemo.php              # demo:seed-large
│  │  │  │  └─ BenchmarkReports.php           # reports:benchmark
│  │  │  ├─ Enums/                            # HouseholdRole, WalletType, WalletVisibility,
│  │  │  │                                    # TransactionType, TransactionStatus, Frequency
│  │  │  ├─ Http/
│  │  │  │  ├─ Controllers/Api/               # Auth, Household, Invitation, Wallet, Category,
│  │  │  │  │                                 # Transaction, Transfer, Budget, RecurringRule,
│  │  │  │  │                                 # Import, Report, Notification
│  │  │  │  ├─ Requests/
│  │  │  │  └─ Resources/
│  │  │  ├─ Imports/
│  │  │  │  ├─ StatementParser.php            # interface
│  │  │  │  ├─ Parsers/ (GenericCsvParser, <Bank>CsvParser)   # satu bank yang kamu pakai
│  │  │  │  ├─ FingerprintCalculator.php
│  │  │  │  ├─ DescriptionNormalizer.php
│  │  │  │  └─ CategorySuggester.php          # stretch: category_rules
│  │  │  ├─ Jobs/ (CheckBudgetThresholds, ProcessImport)
│  │  │  ├─ Models/
│  │  │  ├─ Notifications/ (BudgetThresholdReached, InvitationSent, ReconcileMismatch)
│  │  │  ├─ Policies/ (Household, Wallet, Transaction, Category, Budget, RecurringRule, Import)
│  │  │  ├─ Reports/
│  │  │  │  ├─ VisibleWallets.php             # satu sumber kebenaran visibilitas
│  │  │  │  ├─ MonthlySummaryReport.php
│  │  │  │  ├─ CategoryBreakdownReport.php
│  │  │  │  ├─ TrendReport.php
│  │  │  │  ├─ CashFlowReport.php
│  │  │  │  └─ TransactionCsvExporter.php     # streaming
│  │  │  ├─ Recurring/RecurrenceCalculator.php    # murni, tanpa DB
│  │  │  └─ Support/Money.php
│  │  ├─ database/
│  │  │  ├─ migrations/
│  │  │  ├─ factories/
│  │  │  └─ seeders/ (DefaultCategorySeeder, DemoSeeder)
│  │  ├─ routes/
│  │  │  ├─ api.php                           # /api/households/{household}/... (scopeBindings)
│  │  │  └─ console.php                       # jadwal: recurring, reconcile
│  │  └─ tests/
│  │     ├─ Unit/ (MoneyTest, RecurrenceCalculatorTest, FingerprintTest, DescriptionNormalizerTest)
│  │     ├─ Feature/
│  │     │  ├─ Auth/ (SpaAuthTest, MobileTokenTest)
│  │     │  ├─ Households/ (InvitationTest, RolesTest)
│  │     │  ├─ Wallets/ (BalanceConsistencyTest, TransferTest, ReconcileTest)
│  │     │  ├─ Transactions/ (ClientIdIdempotencyTest, SignConstraintTest)
│  │     │  ├─ Budgets/ (BudgetAlertTest)
│  │     │  ├─ Recurring/ (RecurringIdempotencyTest, CatchUpTest, PendingConfirmationTest)
│  │     │  ├─ Imports/ (ImportDedupeTest, OverlappingStatementTest)
│  │     │  ├─ Reports/ (ReportAccuracyTest, ExportCsvTest)
│  │     │  └─ Privacy/ (PrivateWalletVisibilityTest)   # list, detail, report, export, budget
│  │     └─ Fixtures/statements/              # CSV anonim
│  ├─ web/                                    # React + Vite + TS
│  │  ├─ src/
│  │  │  ├─ main.tsx, router.tsx
│  │  │  ├─ api/ (client.ts, households.ts, wallets.ts, transactions.ts, budgets.ts,
│  │  │  │       recurring.ts, imports.ts, reports.ts)
│  │  │  ├─ hooks/                            # TanStack Query: useWallets, useTransactions, ...
│  │  │  ├─ pages/
│  │  │  │  ├─ auth/ (LoginPage, RegisterPage, AcceptInvitationPage)
│  │  │  │  ├─ DashboardPage.tsx
│  │  │  │  ├─ TransactionsPage.tsx
│  │  │  │  ├─ WalletsPage.tsx
│  │  │  │  ├─ CategoriesPage.tsx
│  │  │  │  ├─ BudgetsPage.tsx
│  │  │  │  ├─ RecurringPage.tsx
│  │  │  │  ├─ imports/ (ImportUploadPage, ImportPreviewPage)
│  │  │  │  ├─ ReportsPage.tsx
│  │  │  │  └─ settings/ (MembersPage, HouseholdPage)
│  │  │  ├─ components/
│  │  │  │  ├─ ui/                            # hasil shadcn add
│  │  │  │  ├─ money/ (MoneyInput, MoneyText)
│  │  │  │  ├─ transactions/ (TransactionForm, TransferForm, TransactionTable)
│  │  │  │  ├─ budgets/ (BudgetProgress)
│  │  │  │  ├─ charts/ (TrendBarChart, CategoryDonut, CashFlowLine)
│  │  │  │  └─ layout/ (AppShell, HouseholdSwitcher, NotificationBell)
│  │  │  └─ lib/dates.ts
│  │  └─ e2e/                                 # Playwright: satu alur (catat → budget → laporan)
│  └─ mobile/                                 # Expo
│     ├─ metro.config.js                      # watchFolders → packages/shared
│     ├─ app/                                 # Expo Router
│     │  ├─ _layout.tsx
│     │  ├─ (auth)/login.tsx
│     │  └─ (app)/
│     │     ├─ index.tsx                      # quick-add
│     │     ├─ transactions.tsx
│     │     ├─ sync.tsx                       # status antrean
│     │     └─ settings.tsx                   # ganti household, logout
│     └─ src/
│        ├─ api/client.ts                     # bearer token dari secure-store
│        ├─ offline/
│        │  ├─ db.ts                          # expo-sqlite: tabel queue + cache
│        │  ├─ queue.ts                       # enqueue (client_id dibuat di sini), status transitions
│        │  ├─ sync-engine.ts                 # flush berurutan, backoff, klasifikasi error
│        │  ├─ sync-engine.test.ts
│        │  └─ reference-cache.ts             # wallet & kategori untuk offline
│        ├─ components/ (AmountKeypad, CategoryGrid, WalletChips, SyncBadge)
│        └─ hooks/ (useNetworkStatus, useSyncQueue)
├─ packages/
│  └─ shared/
│     └─ src/
│        ├─ money.ts                          # formatIDR, parseIDRInput (tanpa float) + test
│        ├─ schemas/                          # zod: transaction, transfer, wallet, budget
│        ├─ types/api.ts
│        └─ client-id.ts                      # newClientId()
├─ docs/
│  ├─ adr/ (001 … 010)
│  ├─ benchmarks/reports.md                   # EXPLAIN + p50/p95
│  ├─ dogfooding-log.md                       # catatan pemakaian nyata
│  └─ diagrams/                               # alur transaksi, recurring, import, sync mobile
├─ load-tests/
│  └─ concurrent-writes.js                    # k6: tulis paralel + client_id duplikat → reconcile
├─ docker/
│  ├─ app.Dockerfile                          # php-fpm, dipakai juga worker & scheduler
│  ├─ web.Dockerfile                          # build vite → disalin ke nginx
│  ├─ nginx/default.conf                      # / → SPA, /api & /sanctum → php-fpm
│  └─ mysql/my.cnf
├─ docker-compose.yml                         # app, worker, scheduler, nginx, mysql (+ mailpit dev)
├─ .github/workflows/ci.yml
├─ .npmrc                                     # node-linker=hoisted (Metro + pnpm)
├─ Makefile                                   # make up, seed, demo, test, bench, reconcile
├─ pnpm-workspace.yaml
└─ README.md
```

---

## Poin yang Harus Terlihat di Portofolio

- **Cerita nyata di bagian atas README**: dipakai sejak kapan, berapa transaksi, 2–3 bug nyata dari `dogfooding-log.md` dan perbaikannya.
- **Bagian "Kenapa stack ini"**: apa yang dipakai, apa yang sengaja tidak (Redis, rollup, dll), dan kapan keputusannya dibalik.
- Test konsistensi saldo + k6 tulis paralel → `wallets:reconcile` selisih 0.
- Transfer yang tidak muncul di laporan pemasukan/pengeluaran, dibuktikan test.
- Scheduler jalan dua kali (dan paralel) → tetap satu transaksi berulang; tanggal 31 tidak drift.
- Alert budget yang terkirim tepat sekali per ambang.
- Test privasi: wallet privat tidak bocor di list, detail, laporan, export, maupun budget.
- Import ulang mutasi yang sama → 0 baris baru, dua transaksi identik sah tetap masuk.
- Tabel `EXPLAIN` + p95 laporan di 200 ribu (+1 juta noise) transaksi: bukti index cukup, tanpa rollup dan tanpa Redis.
- GIF mobile: mode pesawat → catat → online → tersinkron tanpa duplikat.
- ADR yang menjelaskan kenapa "biasa" itu pilihan sadar (database queue, tanpa Redis, tanpa rollup, `client_id` alih-alih tabel idempotency) beserta kapan keputusan itu harus dibalik.

## Risiko yang Perlu Diwaspadai

- **Float menyelinap:** `parseFloat`, `Number("1.250")` (hasilnya 1.25), pembagian persen di PHP. Satu helper, aturan lint, test.
- **Format angka Indonesia:** titik ribuan, koma desimal. CSV bank sering pakai format berbeda per bank; uji dengan fixture dari bank yang kamu pakai.
- **Timezone:** simpan `occurred_on` sebagai `DATE` lokal. Scheduler pakai timezone household, bukan UTC server.
- **Wallet privat bocor lewat laporan:** paling sering terjadi saat menambah endpoint baru. Semua query transaksi wajib lewat `VisibleWallets`, dan test privasi mencakup setiap endpoint laporan.
- **Deadlock transfer:** selalu kunci wallet berurutan id.
- **Edit transfer setengah jalan:** kedua baris selalu diubah dalam satu transaksi DB.
- **Drift tanggal berulang:** hitung dari anchor `day_of_month`, bukan dari jatuh tempo sebelumnya.
- **Budget dihitung dari transaksi pending atau transfer:** filter `status = 'posted' AND type = 'expense'`.
- **Seed tanpa noise:** angka `EXPLAIN` terlihat bagus padahal index household tidak pernah diuji.
- **Mobile mengirim ulang dengan `client_id` baru:** `client_id` dibuat saat item masuk antrean dan disimpan bersama item, bukan saat request dikirim.
- **Cek `client_id` sebelum wallet dikunci:** dua retry paralel bisa sama-sama lolos cek. Urutannya harus kunci wallet dulu, baru cek `client_id`.
- **Metro + pnpm:** symlink pnpm sering membuat Metro gagal resolve `packages/shared`. Pakai `node-linker=hoisted` dan atur `watchFolders`; tes bundling mobile sejak minggu 1, jangan menunggu minggu 8.
- **Data asli keluarga:** jangan commit CSV mutasi asli, jangan pakai data asli di screenshot, backup + uji restore sebelum dipakai serius.
- **Scope creep:** multi-currency, investasi/saham, OCR struk, sinkronisasi API bank, split bill, gamifikasi, parser bank tambahan. Taruh di "next steps".
- **Waktu UI:** pakai default shadcn/ui dan Recharts. Waktu paling berharga ada di kebenaran saldo, test, dan pemakaian nyata.
