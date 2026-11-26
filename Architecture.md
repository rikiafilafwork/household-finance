# Keuangan Pribadi + Budget Planner — Roadmap 9 Minggu

Aplikasi pencatatan keuangan pribadi dengan rencana bulanan (template, checklist, amplop), perbandingan rencana vs aktual, dan dashboard di web dan mobile.

Asumsi: ~10–12 jam/minggu (kerja + freelance + kuliah jalan terus). Kalau waktu ketat, import CSV (minggu 6) dijadikan stretch dan total jadi 8 minggu.

Stack: Laravel 13, MySQL 8, Pest, Sanctum (cookie web + token mobile), queue & cache driver `database`, React + Vite + TanStack Query + shadcn/ui + Recharts, React Native (Expo), Docker Compose.

Tujuan portofolio: project "teknologi biasa tapi rapi" yang **dipakai sendiri**. Nilai jualnya: uang dimodelkan benar, saldo tidak pernah meleset (ada buktinya), rencana vs aktual konsisten di semua layar, laporan dan dashboard cepat tanpa trik, dan mobile offline yang tidak bikin data dobel. Ditambah cerita nyata di README: "dipakai sendiri sejak minggu ke-4, N transaksi, bug nyata yang ketemu".

---

## Fitur Utama

1. **Akun** tiga tipe, masing-masing boleh banyak: Rekening (bank), E-wallet, Dompet (tunai).
2. **Transaksi** pemasukan, pengeluaran, transfer (tarik tunai, top up e-wallet, pindah rekening), dan penyesuaian saldo.
3. **Rencana bulanan** berisi item pemasukan dan pengeluaran:
   - **Checklist**: item satu kali per bulan (gaji, cicilan, listrik). Dicentang → masuk sebagai transaksi, item tercoret.
   - **Amplop**: target bulanan untuk pengeluaran variabel (makan, transport). Aktual dihitung dari transaksi manual di kategori itu.
   - Bisa tambah item manual, lewati item, dan bandingkan rencana vs aktual.
4. **Template bulanan** supaya rencana terisi sendiri tiap awal bulan, opsional auto-post untuk autodebet atau gaji.
5. **Dashboard** (web dan mobile): pemasukan, pengeluaran, sisa anggaran, rencana vs aktual per kategori, tren, item yang perlu dicentang.
6. **Alert amplop** di 80% dan 100%.
7. **Import CSV** mutasi rekening dengan deteksi duplikat.
8. **Laporan** dan export CSV.
9. **Mobile** dengan catat cepat dan centang offline.

## Flow Utama

```
Register → buat akun (Rekening / E-wallet / Dompet + saldo awal)
        → buat template bulanan (gaji, cicilan, listrik, amplop makan, ...)
Awal bulan → rencana bulan itu terisi dari template (atau "Salin dari bulan lalu")
Sepanjang bulan:
   • bayar tagihan / terima gaji → centang item → transaksi tercatat, saldo berubah, item tercoret
   • belanja harian → catat transaksi → masuk amplop kategorinya (atau "di luar rencana")
   • transfer (tarik tunai, top up) → tidak dihitung sebagai pemasukan/pengeluaran
   • amplop ≥ 80% / 100% → notifikasi
Kapan saja → dashboard: rencana vs aktual, sisa anggaran, yang belum dibayar
Akhir bulan → laporan, export CSV
Opsional → import mutasi rekening CSV → preview → commit
```

---

## Pilihan Stack

| Komponen                                                       | Alasan                                                                                                                                                                                                                              | Kapan dibalik                                                                               |
| -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Laravel 13 + MySQL 8 + Pest                                    | Data relasional, saldo butuh transaksi ACID + `lockForUpdate`. MySQL 8 sudah punya CHECK constraint, CTE, window function, generated column, index komposit: semua dipakai. MongoDB/NoSQL tidak cocok untuk data uang               | Tidak ada alasan di skala personal                                                          |
| Queue, cache, session: driver `database`                       | Beban ringan. Job-nya cuma cek alert, parse import, generate plan. Satu worker + satu scheduler cukup                                                                                                                               | Kalau job menunggu > beberapa detik di antrean, atau lock cache jadi bottleneck (ukur dulu) |
| Sanctum (cookie web + token mobile)                            | Dua jenis klien butuh dua mode auth, Sanctum menyediakan keduanya. Passport/OAuth2 overkill                                                                                                                                         | Kalau ada klien pihak ketiga                                                                |
| React + Vite + TanStack Query + shadcn/ui + Recharts           | React (bukan Vue) supaya `packages/shared` (zod schema + `money.ts`) bisa dipakai web **dan** React Native. TanStack Query dibutuhkan untuk invalidasi saldo, plan, dan dashboard setelah tiap transaksi. Tidak perlu Redux/Zustand | Tidak ada                                                                                   |
| React Native (Expo) + `expo-sqlite`                            | Mobile offline itu requirement; antrean harus tahan crash dan berurutan, jadi SQLite lebih tepat daripada array JSON. Dashboard mobile tanpa library chart (progress bar dari `View`)                                               | Kalau antrean ternyata < 20 item, MMKV cukup                                                |
| Docker Compose: `app`, `worker`, `scheduler`, `nginx`, `mysql` | 5 container ringan. `worker` dan `scheduler` wajib karena queue `database` butuh `queue:work` dan jadwal butuh `schedule:work`                                                                                                      | Tidak ada                                                                                   |
| pnpm monorepo (`api`, `web`, `mobile`, `shared`)               | Satu repo untuk satu cerita portofolio. Catatan: Metro (Expo) sering bermasalah dengan symlink pnpm; set `node-linker=hoisted` dan atur `watchFolders`                                                                              | Kalau bundling mobile terus rewel, `shared` jadi salinan yang di-sync skrip                 |

**Sengaja tidak dipakai:** Redis, tabel rollup laporan, Octane/Horizon, RabbitMQ, event sourcing/CQRS, microservice, Passport, email transaksional, state manager global, library chart di mobile. Tiap keputusan "tidak" ini punya ADR dan syarat kapan dibalik.

---

## Ringkasan Timeline

| Minggu | Fokus                                                                         | Output utama                                                        |
| ------ | ----------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| 1      | Fondasi: Docker, auth ganda, `Money`, isolasi data per akun, smoke test Metro | Register/login jalan, data satu akun tidak bisa dibaca akun lain    |
| 2      | Akun, transaksi, transfer, saldo atomik, reconcile, `client_id`               | Saldo selalu = saldo awal + SUM transaksi, terbukti test            |
| 3      | Plan: checklist + amplop, centang → transaksi, rencana vs aktual, alert       | Centang/batal centang konsisten, aktual tidak dobel hitung          |
| 4      | Web inti (Akun, Transaksi, Rencana) → **mulai dogfooding**                    | Kamu mencatat transaksi dan plan asli                               |
| 5      | Template bulanan + auto-post, **dashboard (API + web)**                       | Plan bulan baru terisi sendiri, dashboard rencana vs aktual         |
| 6      | Import CSV mutasi rekening + dedupe hash                                      | Import file yang sama 2x → 0 baris baru                             |
| 7      | Laporan + export CSV + seed 200 ribu + `EXPLAIN`                              | Laporan dan dashboard < 100–150 ms tanpa rollup/cache, ada angkanya |
| 8      | Mobile Expo: Beranda, Rencana, Catat, Transaksi, Akun, offline                | Offline → online, retry tidak bikin duplikat                        |
| 9      | Hardening, test, docs, ADR, README                                            | README dengan cerita nyata, angka, screenshot                       |

---

## Keputusan Desain Inti

**1. Uang = integer rupiah, lewat satu helper.**

- Kolom `BIGINT` signed, satuan rupiah.
- `App\Support\Money` satu-satunya pintu: `Money::parse('Rp1.250.000')` → `1250000`, `Money::format(1250000)` → `Rp1.250.000`, `Money::percentOf()` dengan integer.
- Parsing input pakai string (hapus pemisah ribuan `.`), **tidak pernah** `floatval` / `parseFloat`. Format Indonesia: titik = ribuan, koma = desimal. `1.250` itu seribu dua ratus lima puluh, bukan 1,25. Input `,00` boleh (dibuang), `,50` ditolak.
- Perbandingan persen tanpa pembagian: `spent * 100 >= limit * 80`.
- API mengirim angka sebagai integer JSON. Format tampilan lewat `packages/shared/money.ts`, dipakai web dan mobile.

**2. Tanda (sign) amount mengikuti sudut pandang akun.**

- `income` → positif, `expense` → negatif, `transfer` → keluar negatif / masuk positif, `adjustment` → bebas.
- Invariant: `balance = opening_balance + SUM(amount) WHERE deleted_at IS NULL`.
- `CHECK` constraint di DB menegakkan tanda per tipe (MySQL 8.0.16+).

**3. Saldo = kolom denormalisasi + reconcile.**

- `accounts.balance` diubah di **transaksi DB yang sama** dengan insert/update/delete transaksi, setelah `lockForUpdate()` pada akun.
- Transfer mengunci dua akun **berurutan berdasarkan id**.
- Update transaksi = balikkan efek lama, terapkan efek baru (bisa pindah akun → kunci dua akun). Delete = soft delete + balikkan efek.
- `accounts:reconcile` membandingkan `balance` dengan rumus #2, laporkan selisih, `--fix` untuk membetulkan. Dijadwalkan harian; selisih ≠ 0 = bug, muncul sebagai banner di dashboard.
- Saldo negatif **tidak diblokir** (uang tunai sering lupa dicatat), hanya diberi peringatan di UI.
- ADR-002: kenapa tidak hitung saldo on-the-fly (list akun dipanggil di setiap layar, mobile butuh angka cepat) dan kenapa tetap butuh reconcile.

**4. Transfer = 2 baris berpasangan.**

- Satu `transfer_group_id` (UUID), baris keluar di akun asal (negatif), baris masuk di akun tujuan (positif), `category_id` NULL.
- Edit/hapus transfer selalu menyentuh kedua baris dalam satu transaksi DB.
- Pemakaian nyata: tarik tunai (rekening → dompet), top up e-wallet (rekening → e-wallet), pindah antar rekening. Biaya admin dicatat sebagai pengeluaran terpisah.
- Laporan memfilter `type IN ('income','expense')`, jadi transfer dan adjustment otomatis keluar dari perbandingan rencana vs aktual.

**5. Data per akun pengguna terisolasi.**

- Setiap tabel data punya `user_id`. Trait `BelongsToUser` memasang global scope + mengisi `user_id` otomatis, dan semua route memakai route model binding yang ter-scope.
- Registrasi dikontrol env `REGISTRATION_ENABLED` (matikan setelah akun dibuat kalau di-deploy publik). Lupa password: command `user:reset-password`, tanpa email.
- Test wajib: satu akun tidak bisa membaca atau mengubah akun, transaksi, plan, dan laporan akun lain (IDOR) di **setiap** endpoint.

**6. Akun: tiga tipe, bebas banyak.**

- `accounts.type`: `bank` (rekening, transaksi via transfer), `ewallet`, `cash` (dompet, tunai). Contoh: BCA, Bank Jago, GoPay, Dompet.
- Hanya akun `bank` yang punya import CSV mutasi.
- Laporan bonus: pengeluaran tunai vs non-tunai (dari tipe akun transaksi), tanpa tabel tambahan.
- Dompet tunai hampir selalu meleset dari kenyataan. "Sesuaikan saldo" membuat transaksi `adjustment`; selisih kumulatif per bulan ditampilkan sebagai "tak tercatat".

**7. Rencana bulanan: dua mode item.**

| Mode        | Untuk                                                                        | Aktual dihitung dari                                                                                |
| ----------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `checklist` | Item satu kali per bulan: gaji, cicilan, listrik, internet                   | **Satu transaksi** yang terhubung ke item (`transactions.plan_item_id`). Belum dicentang = aktual 0 |
| `envelope`  | Pengeluaran variabel: makan, transport, hiburan (atau pemasukan lepas-lepas) | **Jumlah transaksi** di kategori itu pada bulan itu yang **tidak** terhubung ke item checklist      |

Definisi angka (satu sumber kebenaran, dipakai Rencana, Dashboard, dan Laporan lewat `PlanComparisonQuery`):

- **Rencana** = `SUM(planned_amount)` item bulan itu yang tidak di-skip.
- **Aktual** = `SUM(amount)` **semua** transaksi `income`/`expense` bulan itu (termasuk yang di luar rencana).
  Pengecualian: transaksi yang terhubung ke item checklist dihitung di bulan **item**-nya, bukan bulan tanggal transaksinya (tagihan November yang dibayar 30 Oktober tetap aktual November), supaya rencana vs aktual per item selalu cocok.
- **Di luar rencana** = transaksi yang tidak terhubung ke checklist **dan** kategorinya tidak punya amplop bulan itu.
- Aktual = aktual checklist + aktual amplop + di luar rencana. Transaksi yang sudah dicentang dari checklist tidak ikut masuk amplop kategorinya (mencegah dobel hitung).
- **Sisa anggaran** = rencana pengeluaran − aktual pengeluaran. Sub-angka "belum dibayar" = jumlah rencana item checklist pengeluaran yang belum dicentang.
- Satu bulan hanya boleh punya **satu amplop per kategori** (dijaga unique index lewat generated column).
- Item bisa di-skip (`skipped_at`), mis. tagihan yang tidak ada bulan ini. Item yang di-skip tidak masuk rencana; transaksi kategori amplop yang di-skip dihitung sebagai di luar rencana.
- Plan dibuat lewat: (a) template (#9), (b) "Salin dari bulan lalu", atau (c) tambah manual. Salin hanya menyalin item yang belum ada dari template di bulan itu.

**8. Centang = membuat transaksi (satu transaksi DB).**

```php
DB::transaction(function () use ($data) {
    // urutan kunci tetap: plan item → akun (hindari deadlock)
    $item    = PlanItem::whereKey($data->planItemId)->lockForUpdate()->firstOrFail();
    $account = Account::whereKey($data->accountId)->lockForUpdate()->firstOrFail();

    // idempotensi (#15): setelah terkunci, retry paralel jadi serial
    if ($existing = Transaction::where('client_id', $data->clientId)->first()) {
        return [$existing, false];                     // controller balas 200
    }
    if ($item->mode !== 'checklist')  throw new UnprocessableException();       // 422
    if ($item->skipped_at || Transaction::where('plan_item_id', $item->id)->exists()) {
        throw new PlanItemAlreadyDoneException();                               // 409
    }

    $tx = Transaction::create([
        'account_id' => $account->id, 'category_id' => $item->category_id,
        'type' => $item->type, 'amount' => $item->signedAmount($data->actualAmount),
        'occurred_on' => $data->occurredOn, 'plan_item_id' => $item->id,
        'client_id' => $data->clientId, 'note' => $item->name,
    ]);
    $account->increment('balance', $tx->amount);
    return [$tx, true];
});
```

- Nominal aktual default sama dengan rencana, boleh diubah saat centang. Akun default diambil dari `plan_items.account_id`, boleh diganti.
- **Batal centang** (`UncheckPlanItem`), atau menghapus transaksi yang terhubung dari halaman Transaksi: soft delete transaksi, **`plan_item_id` diset NULL**, saldo dikoreksi, semua dalam satu transaksi DB. NULL penting karena `UNIQUE (plan_item_id)` tetap menghitung baris soft-deleted; tanpa itu item tidak bisa dicentang ulang.
- Mengubah nominal/tanggal/akun transaksi yang terhubung lewat edit transaksi biasa; plan otomatis menampilkan aktual terbaru.
- Item yang sudah dicentang tidak bisa dihapus; batalkan centang dulu. Batal centang boleh kapan saja (reconcile menjaga saldo); mengunci setelah akhir bulan bisa ditambah nanti.

**9. Template bulanan.**

- `plan_templates`: item yang berulang tiap bulan (gaji tgl 25, cicilan tgl 5) atau tiap tahun (asuransi, pajak). Hanya `monthly` dan `yearly`, karena budget itu bulanan.
- `due_on` dihitung **per bulan dari `day_of_month`** (dibatasi hari terakhir bulan itu), bukan bergulir dari tanggal sebelumnya. Tidak ada drift: template tanggal 31 menghasilkan 31 Jan, 28 Feb, 31 Mar.
- Idempotensi: `UNIQUE (template_id, month)` di `plan_items`. Command `plan:generate` jalan berapa kali pun hasilnya sama. Dijadwalkan tanggal 1 pukul 00:10 (timezone user) untuk bulan berjalan, plus tombol "Buat dari template" untuk bulan lain.
- `auto_post` (opsional, hanya checklist): item otomatis dicentang saat jatuh tempo (autodebet, gaji transfer). Command `plan:auto-post` harian memakai Action `CheckPlanItem` yang sama, jadi kebal dijalankan dua kali (item sudah punya transaksi → dilewati).
- Mengubah template tidak mengubah item bulan yang sudah ada.

**10. Alert amplop.**

- Setelah transaksi expense commit (`afterCommit`), job `CheckEnvelopeThresholds` menghitung ulang amplop kategori + bulan transaksi itu (termasuk yang di-backdate).
- Ambang 80 dan 100: insert `budget_alerts` dengan `UNIQUE (plan_item_id, threshold)`. Konflik = sudah pernah alert, tidak ada spam.
- Notifikasi in-app (`database`, lonceng di web dan mobile), tanpa email.
- Hanya amplop yang punya alert; item checklist tidak (nominalnya tetap).

**11. Dashboard: satu endpoint, satu query class, angka sama dengan halaman Rencana.**

- `GET /api/dashboard?month=YYYY-MM` memanggil `DashboardQuery`, yang memakai `PlanComparisonQuery` yang sama dengan halaman Rencana dan Laporan. Tidak ada dua versi perhitungan.
- Isi response:

```json
{
  "month": "2026-11",
  "balances": { "bank": 0, "ewallet": 0, "cash": 0, "total": 0 },
  "income": { "planned": 9500000, "actual": 8000000 },
  "expense": {
    "planned": 5100000,
    "actual": 4075000,
    "unplanned": 180000,
    "unpaid": 500000
  },
  "remaining_budget": 1025000,
  "categories": [
    { "id": 1, "name": "Makan", "planned": 2000000, "actual": 1350000 }
  ],
  "income_sources": [
    { "name": "Gaji", "planned": 8000000, "actual": 8000000, "done": true }
  ],
  "due_items": [
    {
      "id": 12,
      "name": "Listrik",
      "due_on": "2026-11-20",
      "planned": 500000,
      "overdue": true
    }
  ],
  "envelopes_warning": [
    {
      "id": 20,
      "name": "Transport",
      "planned": 600000,
      "actual": 510000,
      "percent": 85
    }
  ],
  "trend": [
    {
      "month": "2026-06",
      "income_actual": 0,
      "expense_actual": 0,
      "expense_planned": 0
    }
  ],
  "recent": [],
  "reconcile_ok": true
}
```

- **Contoh angka (jadikan fixture test):** checklist pengeluaran aktual 1.715.000 (cicilan 1.200.000 + internet 350.000 + pulsa 165.000) + amplop aktual 2.180.000 (makan 1.350.000 + transport 510.000 + hiburan 320.000) + di luar rencana 180.000 = **4.075.000**. Rencana pengeluaran 5.100.000, sisa anggaran **1.025.000**, dengan 500.000 di antaranya belum dibayar (listrik).
- Tidak ada cache. Beberapa query kecil ber-index; bukti ukurannya di minggu 7.

**12. Tanggal transaksi = `DATE` lokal, bukan timestamp.**
`occurred_on` bertipe `DATE` dalam timezone user (`users.timezone`, default `Asia/Jakarta`). Belanja jam 23.30 WIB itu transaksi hari itu, bukan besok. `plan_items.month` = tanggal 1 bulan itu. `created_at` tetap timestamp UTC untuk audit.

**13. Import CSV dengan dedupe berbasis hash.**

- Hanya untuk akun `bank`. Upload → `imports` + file → job parse → baris di `import_rows` (staging) → preview di web → user petakan kategori → commit.
- Parser di belakang interface `StatementParser`: `GenericCsvParser` (pemetaan kolom, preset disimpan) + **satu parser untuk bank yang kamu pakai**. Format CSV bank hanya bisa diuji dengan data asli. Hati-hati: suffix `DB`/`CR`, pemisah ribuan, tanggal tanpa tahun, encoding bukan UTF-8.
- `fingerprint = sha256(account_id | tanggal | amount | deskripsi_dinormalisasi | occurrence_index)`. `occurrence_index` = urutan kemunculan baris identik di file itu, supaya dua kali beli kopi Rp25.000 di hari yang sama tidak dianggap duplikat.
- `transactions.import_hash` dengan `UNIQUE (account_id, import_hash)`. Import ulang atau file tumpang tindih → baris lama ditandai `duplicate`.
- Batasan jujur (ADR-006): dedupe akurat kalau file memuat hari yang **utuh**. Transaksi yang dicatat manual lalu muncul lagi di CSV tidak otomatis terdeteksi; preview menandai kandidat (tanggal ±1 hari + amount sama).
- Transaksi hasil import tidak terhubung ke item plan (masuk amplop kategori, atau "di luar rencana").

**14. Laporan dan dashboard tanpa rollup, dibuktikan dengan angka.**

- Semua dihitung langsung dari `transactions` dan `plan_items` dengan index `(user_id, occurred_on)`, `(user_id, category_id, occurred_on)`, dan `(user_id, month)`.
- Bukti di minggu 7: seed 200 ribu transaksi untuk akun demo **plus ~1 juta transaksi noise di ratusan akun lain**. Tanpa noise, index `user_id` tidak diuji dan angkanya menipu.
- Ukur: `EXPLAIN ANALYZE` sebelum vs sesudah index, p50/p95 dari N run, untuk setiap query laporan **dan** endpoint dashboard.
- Kalau suatu saat lambat, urutan solusinya (ADR-007): index → covering index → summary table bulanan → cache. Redis paling akhir.

**15. Idempotensi tulis lewat `client_id`.**

- Klien (web dan mobile) membuat UUID `client_id` **per item yang akan dikirim** dan menyertakannya di body `POST` transaksi, transfer, dan centang plan. Web membuatnya per submit form; mobile membuatnya saat item masuk antrean dan menyimpannya bersama item.
- `transactions.client_id` dengan `UNIQUE (user_id, client_id)`. Di dalam Action, **setelah** baris terkunci, cek dulu apakah `client_id` sudah ada. Kalau ya, balas transaksi yang ada (status `200`, bukan `201`) tanpa efek apa pun. Unique index jadi jaring terakhir.
- Transfer: `client_id` dipasang di baris **keluar**, baris masuk NULL.
- Transaksi dari `plan:auto-post` dan import tidak punya `client_id`; idempotensi mereka lewat `plan_item_id` unik dan `import_hash`.
- Batasan jujur (ADR-010): retry dengan `client_id` sama tapi isi berbeda dibalas transaksi lama tanpa peringatan. Tidak masalah karena `client_id` dibuat per item.

**16. Mobile offline: create-only (termasuk centang).**

- Offline yang didukung: **catat transaksi** dan **centang item plan**. Edit, hapus, dan batal centang wajib online. Tidak ada sync engine dua arah. ADR-008.
- `occurred_on` diambil saat user mencatat, bukan saat sync.
- Antrean di `expo-sqlite`, dikirim berurutan saat online, retry dengan backoff memakai `client_id` yang sama.
- Centang item yang ternyata sudah dicentang dari perangkat lain → server balas `409` → item antrean `failed` dengan pesan jelas, dibereskan manual dari layar sinkronisasi.
- Beranda dan data referensi (akun, kategori, plan) disimpan sebagai snapshot terakhir untuk tampilan offline, dengan label "diperbarui HH.MM".

**17. Tanpa Redis.** Queue, cache, dan session memakai `database`. Satu worker dan satu scheduler cukup. Redis baru dipertimbangkan kalau ada angka yang membuktikan perlu. ADR-009.

---

## Tampilan dan Fitur per Platform

Gaya: shadcn/ui default (bersih, abu-abu netral), hijau untuk selesai, kuning untuk ≥ 80%, merah untuk melewati batas. Label bahasa Indonesia, format `Rp1.250.000`.

### Web (lengkap: perencanaan dan analisis)

| Halaman                 | Isi                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Beranda (dashboard)** | Lihat bagian "Dashboard web" di bawah                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| **Rencana**             | Layar utama. Pemilih bulan, tombol "Buat dari template" / "Salin dari bulan lalu". Tiga ringkasan (pemasukan, pengeluaran, sisa anggaran). Bagian **Pemasukan** dan **Pengeluaran tetap** berupa checklist dengan kolom Rencana / Aktual / Selisih (item selesai tercoret, selisih > 0 merah). Bagian **Amplop** dengan progress bar (biru → kuning ≥ 80% → merah > 100%). Baris "di luar rencana" dan "+ Tambah item". Centang membuka form kecil: nominal aktual, akun, tanggal |
| **Transaksi**           | Tabel + filter (akun, kategori, tipe, tanggal, cari catatan), tambah/edit/hapus, transfer. Badge pada transaksi yang terhubung ke item plan                                                                                                                                                                                                                                                                                                                                       |
| **Akun**                | Kartu per tipe (Rekening, E-wallet, Dompet), saldo, "sesuaikan saldo", arsipkan                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Laporan**             | Rencana vs aktual per kategori, tren 6 bulan, cash flow harian, tunai vs non-tunai, export CSV                                                                                                                                                                                                                                                                                                                                                                                    |
| **Impor**               | Wizard upload → preview → commit (akun rekening)                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **Pengaturan**          | Kategori, template bulanan, zona waktu, ganti password                                                                                                                                                                                                                                                                                                                                                                                                                            |

### Dashboard web (Beranda)

1. **Pemilih bulan** + banner kalau `reconcile_ok = false`.
2. **Empat kartu KPI:** Total saldo (pecahan Rekening / E-wallet / Dompet); **Pemasukan** aktual dari rencana + progress bar; **Pengeluaran** aktual dari rencana + progress bar + chip "di luar rencana"; **Sisa anggaran** dengan sub-teks "N belum dibayar".
3. **Chart rencana vs aktual per kategori** (bar horizontal berpasangan; warna aktual kuning/merah mengikuti persen).
4. **Pemasukan:** daftar per sumber (Gaji, Freelance, ...) rencana vs aktual dengan status diterima/belum, dan **tren 6 bulan** (bar pemasukan vs pengeluaran, garis rencana pengeluaran).
5. **Perlu dicentang:** item jatuh tempo ≤ 7 hari atau terlambat, dengan tombol centang cepat.
6. **Amplop hampir habis** (≥ 80%).
7. **Saldo per akun** dan **transaksi terbaru** (5 baris).

### Mobile (cepat, harian)

Lima tab: **Beranda, Rencana, Catat (tengah), Transaksi, Akun.**

| Layar                   | Isi                                                                                                                                                                                                                                                                                                     |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Beranda (dashboard)** | Scroll satu kolom, pull-to-refresh: total saldo + chip 3 tipe; kartu Pemasukan dan Pengeluaran (angka + progress bar, chip "di luar rencana"); "Perlu dicentang (N)" dengan tombol centang; 3 amplop teratas; 5 transaksi terbaru. Tanpa library chart. Offline: snapshot terakhir + "diperbarui HH.MM" |
| **Rencana**             | Checklist bulan ini + progres amplop. Centang → sheet konfirmasi singkat (nominal, akun). Badge "N menunggu sinkron"                                                                                                                                                                                    |
| **Catat**               | Keluar / Masuk / Transfer, keypad besar, chip kategori dan akun, simpan. Target < 5 detik. Kategori yang punya amplop otomatis masuk amplop itu                                                                                                                                                         |
| **Transaksi**           | Daftar per tanggal, item antrean ditandai "belum terkirim"                                                                                                                                                                                                                                              |
| **Akun**                | Saldo per akun (penanda "estimasi" saat offline)                                                                                                                                                                                                                                                        |

Sengaja hanya di web: laporan lengkap, import CSV, kelola kategori dan template, edit/hapus transaksi lama, batal centang.

---

## Minggu 1 — Fondasi

- Monorepo pnpm: `apps/api` (Laravel), `apps/web`, `apps/mobile`, `packages/shared`. `.npmrc` dengan `node-linker=hoisted`. **Smoke test:** app Expo kosong yang meng-import `packages/shared/money.ts` harus bisa di-bundle sekarang, bukan di minggu 8.
- `docker-compose.yml`: `app` (php-fpm), `nginx`, `mysql`, `worker` (`queue:work`), `scheduler` (`schedule:work`), image PHP yang sama. Nginx satu origin: `/` → build web, `/api` dan `/sanctum` → Laravel.
- Laravel 13 + Pest + Larastan + Pint. Migration default (`users`, `sessions`, `cache`, `jobs`, `notifications`, `personal_access_tokens`) + `users.timezone`.
- Auth: Sanctum SPA (`/sanctum/csrf-cookie`, `POST /api/auth/login`, `/logout`, `/register` dengan `REGISTRATION_ENABLED`), mobile `POST /api/auth/token` (email, password, `device_name`) dan `DELETE /api/auth/token`. Rate limit login. Command `user:reset-password`.
- Register → seed kategori default (Makan, Transport, Tagihan, Hiburan, Belanja, Kesehatan, Pendidikan, Lainnya; Gaji, Freelance, Lainnya).
- Trait `BelongsToUser` (global scope + auto `user_id`) + test isolasi antar akun.
- `Money` + unit test lengkap (parse berbagai format, tolak desimal non-nol, format, persen). Enum PHP: `AccountType`, `TransactionType`, `PlanMode`, `PlanItemType`, `Frequency`.

**Done kalau:** register → login (cookie dan token) → kategori default muncul; akun lain tidak bisa membaca kategori itu di semua endpoint; app Expo kosong ter-bundle dengan `packages/shared`.

## Minggu 2 — Akun, Transaksi, Saldo

- Akun: CRUD (`bank` / `ewallet` / `cash`), `opening_balance` saat dibuat, arsip (bukan hapus kalau sudah ada transaksi).
- Actions: `CreateTransaction`, `UpdateTransaction`, `DeleteTransaction` (menyetel `plan_item_id = NULL` bila ada), `CreateTransfer`, `UpdateTransfer`, `DeleteTransfer`, `AdjustBalance`. Pola kunci dan `client_id` seperti keputusan #3, #8, #15.
- `AdjustBalance`: user memasukkan saldo riil → sistem membuat transaksi `adjustment` sebesar selisihnya. Tidak pernah mengubah `balance` langsung.
- `GET /transactions`: filter akun, kategori, tipe, rentang tanggal, pencarian catatan; **cursor pagination** (`occurred_on DESC, id DESC`).
- Command `accounts:reconcile {--fix}` + jadwal harian.
- Test Pest:
  - Create / update (ubah amount, ubah akun) / delete / transfer / edit transfer / hapus transfer → reconcile selisih 0.
  - Rusak `balance` manual → reconcile mendeteksi; `--fix` membetulkan.
  - `client_id` sama 2x (berurutan dan paralel lewat `Process::pool`) → 1 transaksi, saldo berubah sekali, response kedua `200`.
  - CHECK constraint menolak expense dengan amount positif.
- `load-tests/concurrent-writes.js` (k6): 50 VU menulis ke akun yang sama, sebagian dengan `client_id` duplikat. Setelah selesai, reconcile harus 0.

**Done kalau:** semua test hijau dan k6 + reconcile menunjukkan saldo tidak meleset walau ditulis paralel.

## Minggu 3 — Plan: Checklist dan Amplop

- CRUD `plan_items` (checklist/envelope, income/expense, kategori, nominal rencana, akun default, `due_on`), skip/unskip, "Salin dari bulan lalu". Satu amplop per kategori per bulan (unique index).
- Actions: `CheckPlanItem`, `UncheckPlanItem` (keputusan #8), `SkipPlanItem`, `CopyPlanFromPreviousMonth`.
- `PlanComparisonQuery` (keputusan #7): rencana vs aktual per item dan per kategori, total, "di luar rencana", "belum dibayar", sisa anggaran. Class ini yang dipakai Rencana, Dashboard, dan Laporan.

```sql
-- rencana vs aktual per kategori (pengeluaran), satu bulan
WITH plan AS (
  SELECT category_id, SUM(planned_amount) AS planned
    FROM plan_items
   WHERE user_id = ? AND month = ? AND type = 'expense' AND skipped_at IS NULL
   GROUP BY category_id
), actual AS (
  SELECT category_id, -SUM(amount) AS actual
    FROM transactions
   WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ?
     AND type = 'expense' AND deleted_at IS NULL
   GROUP BY category_id
)
SELECT c.id, c.name, COALESCE(p.planned, 0) AS planned, COALESCE(a.actual, 0) AS actual
  FROM categories c
  LEFT JOIN plan   p ON p.category_id = c.id
  LEFT JOIN actual a ON a.category_id = c.id
 WHERE c.user_id = ? AND c.kind = 'expense' AND (p.planned IS NOT NULL OR a.actual IS NOT NULL);

-- aktual amplop: transaksi kategori itu yang TIDAK terhubung ke item checklist
SELECT pi.id, pi.planned_amount, COALESCE(SUM(ABS(t.amount)), 0) AS actual
  FROM plan_items pi
  LEFT JOIN transactions t
         ON t.user_id = pi.user_id AND t.category_id = pi.category_id
        AND t.type = pi.type AND t.plan_item_id IS NULL AND t.deleted_at IS NULL
        AND t.occurred_on >= pi.month AND t.occurred_on < pi.month + INTERVAL 1 MONTH
 WHERE pi.user_id = ? AND pi.month = ? AND pi.mode = 'envelope' AND pi.skipped_at IS NULL
 GROUP BY pi.id, pi.planned_amount;
```

- Job `CheckEnvelopeThresholds` (`afterCommit`) + `budget_alerts` unik + notifikasi in-app.
- Test:
  - Centang → 1 transaksi, saldo benar, `plan_item_id` terisi, aktual item = nominal aktual (termasuk selisih dari rencana).
  - Centang 2x (berurutan, paralel, `client_id` sama dan beda) → tetap 1 transaksi; yang kedua dengan `client_id` beda → `409`.
  - Batal centang → transaksi soft-deleted, saldo kembali, item bisa dicentang lagi; reconcile 0.
  - Hapus transaksi terhubung dari endpoint transaksi → item kembali belum dicentang.
  - Transaksi checklist **tidak** ikut dihitung di amplop kategori yang sama; transaksi manual ikut.
  - "Di luar rencana" dan fixture angka dashboard (keputusan #11) menghasilkan 4.075.000 / 1.025.000.
  - Alert: 79% tidak ada; tembus 80% → 1; masih 80–99% → tetap 1; tembus 100% → alert kedua.
  - Item di-skip keluar dari rencana; item yang sudah dicentang tidak bisa dihapus.
  - Dua amplop untuk kategori yang sama di bulan yang sama ditolak DB.

**Done kalau:** semua test di atas hijau dan reconcile 0 setelah rangkaian centang/batal/hapus acak (test properti sederhana).

## Minggu 4 — Web Inti + Mulai Dogfooding

Tujuannya bukan web yang lengkap, tapi cukup untuk **dipakai sendiri mulai minggu ini**. Semakin awal dipakai, semakin banyak cerita nyata untuk README.

- Stack: React + Vite + TypeScript, React Router, TanStack Query, shadcn/ui, `react-hook-form` + `zod` (schema dari `packages/shared`).
- `api/client.ts`: axios dengan `withCredentials`, CSRF cookie sekali, interceptor 401 → login, 403/409 → toast, 422 → pesan form. Setiap form transaksi/centang membuat `client_id` baru **per submit**, bukan per klik.
- `MoneyInput`: tampil `1.250.000` saat diketik, nilai form tetap integer. Tidak ada `parseFloat` di seluruh codebase (aturan lint).
- Halaman: login/register, **Akun**, **Transaksi** (+ transfer), **Rencana** (checklist + amplop + form centang), **Beranda sederhana** (total saldo + ringkasan), kategori, lonceng notifikasi.
- Setelah centang/transaksi: invalidasi query `accounts`, `transactions`, `plan`, `dashboard`.
- Deploy minimal ke VPS (atau laptop di LAN). Backup MySQL harian (`mysqldump` via cron) sejak data asli masuk.
- Buat `docs/dogfooding-log.md`: catat setiap hal yang mengganggu saat dipakai. Ini bahan README dan prioritas minggu berikutnya.

**Done kalau:** kamu mencatat plan dan transaksi asli selama seminggu lewat web, dan reconcile harian tetap 0.

## Minggu 5 — Template Bulanan + Dashboard

**Template:**

- `plan_templates` CRUD (monthly/yearly, `day_of_month`, `auto_post`). Command `plan:generate {--month=}` (tanggal 1, 00:10 zona waktu user) dan tombol "Buat dari template". Command `plan:auto-post` harian.
- Test:
  - `plan:generate` 2x (berurutan dan paralel) → item tidak dobel (`UNIQUE (template_id, month)`).
  - Template tanggal 31 → 31 Jan, 28 Feb (29 di kabisat), 31 Mar.
  - Template yearly hanya muncul di bulannya.
  - `plan:auto-post` 2x → 1 transaksi, saldo benar. Item yang sudah dicentang manual dilewati.
  - Mengubah template tidak mengubah item bulan yang sudah ada.
- Pakai untuk data asli: gaji, cicilan, internet, listrik.

**Dashboard (API + web):**

- `DashboardQuery` + `GET /api/dashboard?month=` (keputusan #11), memakai `PlanComparisonQuery`.
- Web Beranda lengkap sesuai bagian "Dashboard web": KPI, chart rencana vs aktual per kategori, pemasukan per sumber + tren 6 bulan, "perlu dicentang" dengan centang cepat, amplop hampir habis, saldo per akun, transaksi terbaru, banner reconcile.
- Test: dashboard cocok dengan fixture hitungan manual; semua angka di Beranda sama dengan halaman Rencana untuk bulan yang sama; bulan tanpa plan tidak error (empty state + CTA "Buat dari template").

**Done kalau:** di awal bulan plan terisi sendiri dari template, dan Beranda menampilkan angka rencana vs aktual yang sama persis dengan halaman Rencana.

## Minggu 6 — Import CSV Mutasi Rekening

- `POST /imports` (upload, pilih akun rekening + format) → job `ParseImport` → `import_rows` dengan status `new` / `duplicate` / `possible_duplicate` / `invalid`.
- Parser: `GenericCsvParser` + parser untuk bank yang kamu pakai (#13). Deteksi encoding (`mb_detect_encoding` → UTF-8), BOM, delimiter `,`/`;`.
- `FingerprintCalculator` + `DescriptionNormalizer` + unit test.
- `POST /imports/{id}/commit`: baris `new` yang dicentang → transaksi dalam batch (chunk 500 per transaksi DB), saldo diupdate sekali per batch dengan `lockForUpdate`. `insertOrIgnore` pada `UNIQUE (account_id, import_hash)` sebagai jaring kedua.
- Web: wizard upload → preview (tabel, badge duplikat/kandidat duplikat, pilih kategori) → commit → ringkasan.
- **Stretch:** `category_rules` (kata kunci → kategori), dibuat dari koreksi user.
- Fixture CSV anonim di `tests/Fixtures/statements/` (jangan commit mutasi asli).
- Test: file sama 2x → impor kedua 0 baris baru; dua file tumpang tindih → tidak ada dobel; dua baris identik sah di satu file → keduanya masuk; commit di-retry setelah gagal di tengah → tidak dobel; reconcile 0; akun non-rekening ditolak.

**Done kalau:** mutasi rekening asli sebulan bisa diimpor, dan mengimpor ulang tidak menambah apa pun.

## Minggu 7 — Laporan, Export, Bukti Performa

**API laporan** (hanya `type IN ('income','expense')` kecuali cash flow):

- `GET /reports/summary?month=` → pemasukan, pengeluaran, net, rencana vs aktual, perbandingan bulan lalu.
- `GET /reports/by-category?from&to&kind=` → total per kategori + persen + rencana.
- `GET /reports/trend?months=6` → pemasukan vs pengeluaran (+ rencana) per bulan.
- `GET /reports/cash-flow?from&to&account=` → net harian + saldo berjalan (window function, dimulai dari saldo sebelum `from`).
- `GET /reports/cash-vs-noncash?month=` → pengeluaran per tipe akun.
- `GET /reports/export.csv?from&to` → streaming (`streamDownload` + `lazyById`), UTF-8 dengan BOM, delimiter bisa dipilih (`;` untuk Excel locale Indonesia).

```sql
-- tren 6 bulan
SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym,
       SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END)  AS income,
      -SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END)  AS expense
  FROM transactions
 WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ?
   AND type IN ('income', 'expense') AND deleted_at IS NULL
 GROUP BY ym ORDER BY ym;

-- cash flow dengan saldo berjalan
SELECT occurred_on, SUM(amount) AS net,
       ? + SUM(SUM(amount)) OVER (ORDER BY occurred_on) AS running_balance   -- ? = saldo sebelum `from`
  FROM transactions
 WHERE user_id = ? AND occurred_on BETWEEN ? AND ? AND deleted_at IS NULL
 GROUP BY occurred_on;
```

**Bukti performa:**

- `php artisan demo:seed-large`: akun demo 200 ribu transaksi (3 tahun, ~8 akun, distribusi realistis, plan 36 bulan) + ~1 juta transaksi di 500 akun lain. Insert batch 5.000 baris, bukan factory satu per satu.
- `php artisan reports:benchmark`: tiap query laporan **dan** `GET /dashboard` dijalankan 50x, catat p50/p95 dan `EXPLAIN ANALYZE`, simpan ke `docs/benchmarks/reports.md`.
- Tabel: tanpa index → `(user_id, occurred_on)` → covering index (opsional). Target hipotesis: laporan < 100 ms p95, dashboard < 150 ms p95 di laptop. Sesuaikan dengan hasil ukur; tulis spesifikasi mesin dan versi MySQL.

**Web:** halaman Laporan dengan tab Ringkasan, Kategori (rencana vs aktual), Tren, Cash flow, Tunai vs non-tunai, tombol Export.

**Done kalau:** angka laporan cocok dengan fixture, akun lain tidak bisa melihat angka akun demo (laporan dan export), dan `docs/benchmarks/reports.md` berisi angka + `EXPLAIN`.

## Minggu 8 — Mobile (Expo)

- Expo Router + TypeScript, TanStack Query, `expo-secure-store` (token), `expo-sqlite` (antrean + snapshot), `@react-native-community/netinfo`, `expo-crypto`.
- Lima tab sesuai bagian "Tampilan dan Fitur per Platform". Progress bar dashboard dari `View`, tanpa library chart.
- **Beranda:** satu panggilan `GET /dashboard`, disimpan sebagai snapshot SQLite; offline menampilkan snapshot + "diperbarui HH.MM".
- **Rencana:** checklist + amplop; centang membuka sheet konfirmasi (nominal, akun) → masuk antrean.
- **Catat:** keypad, kategori, akun, tipe; kategori beramplop otomatis masuk amplop.
- Cache offline untuk akun, kategori, dan plan bulan berjalan.
- Antrean offline (`client_id` dibuat saat item masuk antrean dan **tidak pernah diganti**; dua jenis item: `create_transaction` dan `check_plan_item`):

| Status     | Arti                                                                                           |
| ---------- | ---------------------------------------------------------------------------------------------- |
| `pending`  | dibuat lokal, belum dikirim                                                                    |
| `sending`  | sedang dikirim                                                                                 |
| `synced`   | server balas 201, atau 200 (sudah ada dari percobaan sebelumnya)                               |
| `retrying` | network error / 5xx → backoff 2s, 10s, 1m, 5m, `client_id` sama                                |
| `failed`   | 422 / 403 / 409 (akun diarsip, item sudah dicentang dari perangkat lain) → butuh tindakan user |

- Flush berurutan saat online, saat app kembali ke foreground, dan setelah item baru masuk. Saldo offline = saldo terakhir + efek item pending, dengan penanda "estimasi".
- Test: unit test sync engine (mock fetch: sukses, timeout, response hilang lalu retry, 422, 409). Manual: mode pesawat → catat 5 transaksi + centang 2 item → online → semuanya di server, reconcile 0. Matikan jaringan **setelah** request terkirim tapi sebelum response diterima → retry → tetap 1 transaksi. Centang item yang sama dari web saat HP offline → HP online → item `failed` dengan pesan jelas, tanpa duplikat.
- Build APK internal (EAS) untuk dipakai sendiri.

**Done kalau:** skenario mode pesawat dan "response hilang" tidak menghasilkan duplikat, Beranda mobile menampilkan angka yang sama dengan web, dan Catat dipakai harian di HP.

## Minggu 9 — Hardening dan Presentasi

- Test: lengkapi yang belum, CI GitHub Actions (Pest + Larastan + Pint + build web + lint mobile), `php artisan test --parallel`. Playwright cukup **satu alur**: centang item → saldo dan dashboard berubah → batal centang → kembali.
- Keamanan: semua route ter-scope per akun (tes IDOR otomatis untuk setiap route), validasi Form Request, rate limit login dan import, ukuran upload maksimum, file import di disk privat dan dihapus setelah commit, header keamanan di Nginx, secret via env, `REGISTRATION_ENABLED=false` di produksi.
- Operasional: backup MySQL harian + uji restore sekali, `accounts:reconcile` harian, `/up` health check, log terstruktur (JSON) dengan `user_id` dan `request_id`.
- Docs (bagian yang dilihat recruiter pertama):
  - README: problem (catatan keuangan tercecer dan budget yang tidak pernah dibandingkan dengan kenyataan), **cerita nyata** (sejak kapan dipakai, berapa transaksi, bug nyata dari `dogfooding-log.md`), screenshot web (Rencana, Beranda) + GIF Catat dan centang di mobile, diagram alur, `docker compose up`, `make demo`.
  - Bagian "Kenapa stack ini": tabel pilihan stack (apa yang dipakai, apa yang sengaja tidak, kapan dibalik).
  - Tabel "Poin teknis" dengan link ke test yang membuktikan masing-masing.
  - Tabel angka laporan + dashboard + `EXPLAIN` dari minggu 7.
  - `docs/adr/`: 001 uang integer, 002 saldo denormalisasi + reconcile, 003 transfer dua baris, 004 model plan (checklist vs amplop, definisi aktual), 005 template bulanan tanpa drift + auto-post idempoten, 006 dedupe import, 007 laporan dan dashboard tanpa rollup/cache, 008 mobile offline create-only, 009 database queue tanpa Redis, 010 idempotensi lewat `client_id`.
  - "Known limitations & what I'd do next": transfer sebagai item plan (tabungan bulanan), menghubungkan transaksi import ke item plan, multi-currency, edit offline + sync dua arah, parser bank tambahan, sinkronisasi bank otomatis, OCR struk, pengingat tagihan (push notification), kunci bulan, tujuan tabungan.
- Screenshot pakai data seed, **bukan** data pribadi asli.

---

## Schema Database (MySQL 8)

Ditulis sebagai SQL supaya ringkas; implementasinya Laravel migration, CHECK constraint dan generated column lewat `DB::statement()` / `virtualAs()`. Semua uang `BIGINT` rupiah. Tabel bawaan Laravel (`users` + kolom `timezone VARCHAR(40) NOT NULL DEFAULT 'Asia/Jakarta'`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications`, `personal_access_tokens`, `password_reset_tokens`) tidak ditulis ulang.

```sql
-- ---------- Akun & kategori ----------

CREATE TABLE accounts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  type            ENUM('bank','ewallet','cash') NOT NULL,    -- rekening | e-wallet | dompet
  name            VARCHAR(100) NOT NULL,                     -- BCA, Bank Jago, GoPay, Dompet
  opening_balance BIGINT NOT NULL DEFAULT 0,
  balance         BIGINT NOT NULL DEFAULT 0,                 -- denormalisasi, dijaga reconcile
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  archived_at     TIMESTAMP NULL,
  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,
  UNIQUE KEY uq_account_name (user_id, name),
  KEY idx_accounts_user_type (user_id, type),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE categories (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  kind        ENUM('income','expense') NOT NULL,
  name        VARCHAR(60) NOT NULL,
  color       VARCHAR(9)  NULL,
  icon        VARCHAR(40) NULL,
  archived_at TIMESTAMP NULL,
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL,
  UNIQUE KEY uq_category_name (user_id, kind, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------- Template & plan bulanan ----------

CREATE TABLE plan_templates (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  type           ENUM('income','expense') NOT NULL,
  mode           ENUM('checklist','envelope') NOT NULL,
  name           VARCHAR(100) NOT NULL,
  category_id    BIGINT UNSIGNED NOT NULL,
  planned_amount BIGINT NOT NULL,
  account_id     BIGINT UNSIGNED NULL,                      -- akun default
  frequency      ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  day_of_month   TINYINT UNSIGNED NULL,                     -- 1..31, dibatasi hari terakhir bulan; NULL untuk envelope
  month_of_year  TINYINT UNSIGNED NULL,                     -- 1..12, hanya yearly
  auto_post      BOOLEAN NOT NULL DEFAULT FALSE,            -- hanya checklist
  is_active      BOOLEAN NOT NULL DEFAULT TRUE,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  KEY idx_templates_user_active (user_id, is_active),
  FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (account_id)  REFERENCES accounts(id),
  CONSTRAINT chk_tpl_amount CHECK (planned_amount > 0),
  CONSTRAINT chk_tpl_auto   CHECK (auto_post = FALSE OR mode = 'checklist')
);

CREATE TABLE plan_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  month          DATE NOT NULL,                             -- selalu tanggal 1 (zona waktu user)
  type           ENUM('income','expense') NOT NULL,
  mode           ENUM('checklist','envelope') NOT NULL,
  name           VARCHAR(100) NOT NULL,
  category_id    BIGINT UNSIGNED NOT NULL,
  planned_amount BIGINT NOT NULL,
  account_id     BIGINT UNSIGNED NULL,                      -- akun default saat dicentang
  due_on         DATE NULL,                                 -- checklist saja
  template_id    BIGINT UNSIGNED NULL,
  source         ENUM('manual','template','copied') NOT NULL DEFAULT 'manual',
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  skipped_at     TIMESTAMP NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  -- satu amplop per kategori per bulan; NULL untuk checklist (boleh berulang)
  envelope_category_id BIGINT UNSIGNED AS (IF(mode = 'envelope', category_id, NULL)) VIRTUAL,

  KEY idx_plan_user_month (user_id, month),
  UNIQUE KEY uq_plan_template_month (template_id, month),                 -- idempotensi plan:generate
  UNIQUE KEY uq_plan_envelope (user_id, month, envelope_category_id),

  FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (account_id)  REFERENCES accounts(id),
  FOREIGN KEY (template_id) REFERENCES plan_templates(id) ON DELETE SET NULL,
  CONSTRAINT chk_plan_amount CHECK (planned_amount > 0),
  CONSTRAINT chk_plan_month  CHECK (DAY(month) = 1),
  CONSTRAINT chk_plan_due    CHECK (mode = 'checklist' OR due_on IS NULL)
);

CREATE TABLE budget_alerts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_item_id BIGINT UNSIGNED NOT NULL,                    -- hanya amplop
  threshold    TINYINT UNSIGNED NOT NULL,                   -- 80 | 100
  spent_amount BIGINT NOT NULL,                             -- snapshot saat alert
  triggered_at TIMESTAMP NOT NULL,
  UNIQUE KEY uq_budget_alert (plan_item_id, threshold),     -- tidak ada spam
  FOREIGN KEY (plan_item_id) REFERENCES plan_items(id) ON DELETE CASCADE
);

-- ---------- Transaksi ----------

CREATE TABLE transactions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  account_id        BIGINT UNSIGNED NOT NULL,
  category_id       BIGINT UNSIGNED NULL,           -- NULL untuk transfer & adjustment
  type              ENUM('income','expense','transfer','adjustment') NOT NULL,
  amount            BIGINT NOT NULL,                -- ber-tanda, sudut pandang akun
  occurred_on       DATE NOT NULL,                  -- tanggal lokal user
  note              VARCHAR(255) NULL,
  transfer_group_id CHAR(36) NULL,                  -- pasangan transfer
  client_id         CHAR(36) NULL,                  -- idempotensi tulis dari web/mobile (#15)
  plan_item_id      BIGINT UNSIGNED NULL,           -- terhubung ke item checklist (#8)
  import_hash       CHAR(64) NULL,                  -- fingerprint import CSV
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  deleted_at        TIMESTAMP NULL,

  KEY idx_tx_user_date     (user_id, occurred_on),               -- laporan, daftar, dashboard
  KEY idx_tx_user_cat_date (user_id, category_id, occurred_on),  -- aktual amplop & per kategori
  KEY idx_tx_account_date  (account_id, occurred_on),            -- daftar per akun, reconcile
  KEY idx_tx_transfer_group (transfer_group_id),
  UNIQUE KEY uq_tx_client    (user_id, client_id),               -- NULL boleh berulang di MySQL
  UNIQUE KEY uq_tx_plan_item (plan_item_id),                     -- 1 item checklist = maks 1 transaksi
  UNIQUE KEY uq_tx_import    (account_id, import_hash),

  FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id)   REFERENCES accounts(id),
  FOREIGN KEY (category_id)  REFERENCES categories(id),
  FOREIGN KEY (plan_item_id) REFERENCES plan_items(id),

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

-- ---------- Import CSV ----------

CREATE TABLE imports (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  account_id        BIGINT UNSIGNED NOT NULL,       -- harus bertipe bank (divalidasi di aplikasi)
  bank_format       VARCHAR(30) NOT NULL,           -- generic | <bank yang dipakai>
  column_mapping    JSON NULL,                      -- untuk generic
  headers           JSON NULL,                      -- nama kolom file (urutan asli); import_rows.raw = daftar sel
  original_filename VARCHAR(255) NOT NULL,
  file_path         VARCHAR(255) NULL,              -- disk privat, dihapus setelah commit
  status            ENUM('pending','parsing','ready','committing','committed','failed') NOT NULL DEFAULT 'pending',
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  new_rows          INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  possible_duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
  invalid_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  error             TEXT NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  KEY idx_imports_user (user_id, created_at),
  FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
);

CREATE TABLE import_rows (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id      BIGINT UNSIGNED NOT NULL,
  row_number     INT UNSIGNED NOT NULL,
  occurred_on    DATE NULL,
  amount         BIGINT NULL,                       -- sudah ber-tanda
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
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  keyword     VARCHAR(100) NOT NULL,                -- dicocokkan ke deskripsi ternormalisasi
  category_id BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL,                      -- koreksi terakhir menimpa kategori
  UNIQUE KEY uq_category_rule (user_id, keyword),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);
```

### Query reconcile

```sql
SELECT a.id, a.balance,
       a.opening_balance + COALESCE(SUM(t.amount), 0) AS expected
  FROM accounts a
  LEFT JOIN transactions t ON t.account_id = a.id AND t.deleted_at IS NULL
 WHERE a.user_id = ?
 GROUP BY a.id, a.balance, a.opening_balance
HAVING a.balance <> expected;
```

### Catatan schema

- Tidak ada kolom `status` di `transactions`: "belum dibayar" diwakili item plan yang belum punya transaksi. Status item (`done`/`planned`/`skipped`) **diturunkan**: done = ada transaksi dengan `plan_item_id` itu (belum soft-deleted), skipped = `skipped_at` terisi. Tidak ada kolom status yang harus disinkronkan.
- `UNIQUE (plan_item_id)` menghitung baris soft-deleted, jadi batal centang dan hapus transaksi terhubung **wajib** menyetel `plan_item_id = NULL` (keputusan #8).
- Generated column `envelope_category_id` + unique index menjaga "satu amplop per kategori per bulan" langsung di DB, tanpa trigger.
- Tidak ada tabel rollup dan tidak ada kolom `spent` di plan. Semuanya dihitung dari `transactions` atau dijaga constraint.
- Ukuran tabel: 200 ribu transaksi × ~150 byte ≈ 30 MB + index. Kecil untuk MySQL; inti buktinya ada di bentuk query dan index, bukan ukuran.

### Index yang melayani query utama

| Query                                                    | Index                             |
| -------------------------------------------------------- | --------------------------------- |
| Daftar transaksi, laporan per rentang tanggal, cash flow | `idx_tx_user_date`                |
| Aktual per kategori, aktual amplop                       | `idx_tx_user_cat_date`            |
| Reconcile, daftar per akun                               | `idx_tx_account_date`             |
| Rencana bulan ini, item jatuh tempo                      | `idx_plan_user_month`             |
| Cek item sudah dicentang / dedupe                        | `uq_tx_plan_item`, `uq_tx_client` |

---

## Struktur Folder

```
personal-finance/
├─ apps/
│  ├─ api/                                   # Laravel 13
│  │  ├─ app/
│  │  │  ├─ Actions/
│  │  │  │  ├─ Accounts/                      # CreateAccount, AdjustBalance, ArchiveAccount, ReconcileAccounts
│  │  │  │  ├─ Transactions/                  # CreateTransaction, UpdateTransaction, DeleteTransaction
│  │  │  │  ├─ Transfers/                     # CreateTransfer, UpdateTransfer, DeleteTransfer
│  │  │  │  ├─ Plan/                          # CheckPlanItem, UncheckPlanItem, SkipPlanItem,
│  │  │  │  │                                 # CopyPlanFromPreviousMonth, GeneratePlanFromTemplates, AutoPostDueItems
│  │  │  │  └─ Imports/                       # ParseImport, CommitImport
│  │  │  ├─ Console/Commands/
│  │  │  │  ├─ ReconcileAccountsCommand.php   # accounts:reconcile {--fix}
│  │  │  │  ├─ GeneratePlanCommand.php        # plan:generate {--month=}
│  │  │  │  ├─ AutoPostPlanCommand.php        # plan:auto-post
│  │  │  │  ├─ ResetUserPassword.php          # user:reset-password
│  │  │  │  ├─ SeedLargeDemo.php              # demo:seed-large
│  │  │  │  └─ BenchmarkReports.php           # reports:benchmark (laporan + dashboard)
│  │  │  ├─ Enums/                            # AccountType, TransactionType, PlanMode, PlanItemType, Frequency
│  │  │  ├─ Http/
│  │  │  │  ├─ Controllers/Api/               # Auth, Account, Category, Transaction, Transfer, PlanItem,
│  │  │  │  │                                 # PlanTemplate, Dashboard, Import, Report, Notification
│  │  │  │  ├─ Requests/
│  │  │  │  └─ Resources/
│  │  │  ├─ Imports/
│  │  │  │  ├─ StatementParser.php            # interface
│  │  │  │  ├─ Parsers/ (GenericCsvParser, <Bank>CsvParser)   # satu bank yang kamu pakai
│  │  │  │  ├─ FingerprintCalculator.php
│  │  │  │  ├─ DescriptionNormalizer.php
│  │  │  │  └─ CategorySuggester.php          # stretch
│  │  │  ├─ Jobs/ (CheckEnvelopeThresholds, ProcessImport)
│  │  │  ├─ Models/
│  │  │  │  └─ Concerns/BelongsToUser.php     # global scope + auto user_id
│  │  │  ├─ Notifications/ (EnvelopeThresholdReached, ReconcileMismatch)
│  │  │  ├─ Queries/
│  │  │  │  ├─ PlanComparisonQuery.php        # satu sumber kebenaran rencana vs aktual
│  │  │  │  ├─ DashboardQuery.php             # memakai PlanComparisonQuery
│  │  │  │  ├─ TrendQuery.php
│  │  │  │  └─ CashFlowQuery.php
│  │  │  ├─ Reports/ (MonthlySummaryReport, CategoryBreakdownReport, CashVsNonCashReport, TransactionCsvExporter)
│  │  │  ├─ Plan/MonthlyDueDate.php           # day_of_month → tanggal, dibatasi hari terakhir bulan
│  │  │  └─ Support/Money.php
│  │  ├─ database/
│  │  │  ├─ migrations/
│  │  │  ├─ factories/
│  │  │  └─ seeders/ (DefaultCategorySeeder, DemoSeeder)
│  │  ├─ routes/
│  │  │  ├─ api.php
│  │  │  └─ console.php                       # jadwal: plan:generate, plan:auto-post, accounts:reconcile
│  │  └─ tests/
│  │     ├─ Unit/ (MoneyTest, MonthlyDueDateTest, FingerprintTest, DescriptionNormalizerTest)
│  │     ├─ Feature/
│  │     │  ├─ Auth/ (SpaAuthTest, MobileTokenTest)
│  │     │  ├─ Isolation/ (DataIsolationTest)   # IDOR di setiap route
│  │     │  ├─ Accounts/ (BalanceConsistencyTest, TransferTest, ReconcileTest)
│  │     │  ├─ Transactions/ (ClientIdIdempotencyTest, SignConstraintTest)
│  │     │  ├─ Plan/ (CheckUncheckTest, EnvelopeActualTest, UnplannedTest, EnvelopeAlertTest,
│  │     │  │         TemplateGenerateTest, AutoPostTest)
│  │     │  ├─ Dashboard/ (DashboardFixtureTest, DashboardMatchesPlanTest)
│  │     │  ├─ Imports/ (ImportDedupeTest, OverlappingStatementTest)
│  │     │  └─ Reports/ (ReportAccuracyTest, ExportCsvTest)
│  │     └─ Fixtures/statements/              # CSV anonim
│  ├─ web/                                    # React + Vite + TS
│  │  ├─ src/
│  │  │  ├─ main.tsx, router.tsx
│  │  │  ├─ api/ (client.ts, accounts.ts, transactions.ts, plan.ts, dashboard.ts, imports.ts, reports.ts)
│  │  │  ├─ hooks/                            # TanStack Query: useAccounts, usePlan, useDashboard, ...
│  │  │  ├─ pages/
│  │  │  │  ├─ auth/ (LoginPage, RegisterPage)
│  │  │  │  ├─ DashboardPage.tsx              # Beranda
│  │  │  │  ├─ PlanPage.tsx                   # Rencana
│  │  │  │  ├─ TransactionsPage.tsx
│  │  │  │  ├─ AccountsPage.tsx
│  │  │  │  ├─ ReportsPage.tsx
│  │  │  │  ├─ imports/ (ImportUploadPage, ImportPreviewPage)
│  │  │  │  └─ settings/ (CategoriesPage, TemplatesPage, ProfilePage)
│  │  │  ├─ components/
│  │  │  │  ├─ ui/                            # hasil shadcn add
│  │  │  │  ├─ money/ (MoneyInput, MoneyText)
│  │  │  │  ├─ plan/ (PlanChecklistRow, EnvelopeRow, CheckItemDialog, PlanSummaryCards)
│  │  │  │  ├─ dashboard/ (KpiCards, CategoryPlanVsActualChart, IncomeSourcesList,
│  │  │  │  │              TrendChart, DueItemsPanel, EnvelopeWarningPanel, RecentTransactions)
│  │  │  │  ├─ transactions/ (TransactionForm, TransferForm, TransactionTable)
│  │  │  │  ├─ charts/ (TrendBarChart, CategoryDonut, CashFlowLine)
│  │  │  │  └─ layout/ (AppShell, MonthPicker, NotificationBell)
│  │  │  └─ lib/dates.ts
│  │  └─ e2e/                                 # Playwright: satu alur (centang → dashboard → batal)
│  └─ mobile/                                 # Expo
│     ├─ metro.config.js                      # watchFolders → packages/shared
│     ├─ app/                                 # Expo Router
│     │  ├─ _layout.tsx
│     │  ├─ (auth)/login.tsx
│     │  └─ (app)/
│     │     ├─ index.tsx                      # Beranda (dashboard)
│     │     ├─ plan.tsx                       # Rencana
│     │     ├─ add.tsx                        # Catat
│     │     ├─ transactions.tsx
│     │     ├─ accounts.tsx
│     │     └─ sync.tsx                       # status antrean
│     └─ src/
│        ├─ api/client.ts                     # bearer token dari secure-store
│        ├─ offline/
│        │  ├─ db.ts                          # expo-sqlite: antrean + snapshot
│        │  ├─ queue.ts                       # enqueue (client_id dibuat di sini), status transitions
│        │  ├─ sync-engine.ts                 # flush berurutan, backoff, klasifikasi error
│        │  ├─ sync-engine.test.ts
│        │  └─ snapshot.ts                    # dashboard, akun, kategori, plan bulan berjalan
│        ├─ components/ (AmountKeypad, CategoryGrid, AccountChips, ProgressBar,
│        │               DashboardCards, PlanChecklist, CheckItemSheet, SyncBadge)
│        └─ hooks/ (useNetworkStatus, useSyncQueue, useDashboard)
├─ packages/
│  └─ shared/
│     └─ src/
│        ├─ money.ts                          # formatIDR, parseIDRInput (tanpa float) + test
│        ├─ schemas/                          # zod: transaction, transfer, account, plan item, check item
│        ├─ types/api.ts                      # termasuk tipe response dashboard
│        └─ client-id.ts                      # newClientId()
├─ docs/
│  ├─ adr/ (001 … 010)
│  ├─ benchmarks/reports.md                   # EXPLAIN + p50/p95 (laporan + dashboard)
│  ├─ dogfooding-log.md                       # catatan pemakaian nyata
│  └─ diagrams/                               # alur centang, template bulanan, import, sync mobile
├─ load-tests/
│  └─ concurrent-writes.js                    # k6: tulis paralel + client_id duplikat → reconcile
├─ docker/
│  ├─ app.Dockerfile                          # php-fpm, dipakai juga worker & scheduler
│  ├─ web.Dockerfile                          # build vite → disalin ke nginx
│  ├─ nginx/default.conf                      # / → SPA, /api & /sanctum → php-fpm
│  └─ mysql/my.cnf
├─ docker-compose.yml                         # app, worker, scheduler, nginx, mysql
├─ .github/workflows/ci.yml
├─ .npmrc                                     # node-linker=hoisted (Metro + pnpm)
├─ Makefile                                   # make up, seed, demo, test, bench, reconcile
├─ pnpm-workspace.yaml
└─ README.md
```

---

## Poin yang Harus Terlihat di Portofolio

- **Cerita nyata di bagian atas README**: dipakai sejak kapan, berapa transaksi, 2–3 bug nyata dari `dogfooding-log.md` dan perbaikannya.
- **Bagian "Kenapa stack ini"**: apa yang dipakai, apa yang sengaja tidak (Redis, rollup, chart mobile, email), dan kapan keputusannya dibalik.
- **Rencana vs aktual yang konsisten**: definisi angka yang jelas (checklist, amplop, di luar rencana) dan test yang membuktikan Beranda = Rencana = Laporan untuk bulan yang sama.
- Test konsistensi saldo + k6 tulis paralel → `accounts:reconcile` selisih 0, termasuk setelah rangkaian centang/batal centang.
- Centang paralel/ganda → tepat satu transaksi; batal lalu centang ulang berfungsi (kasus unique + soft delete).
- `plan:generate` dan `plan:auto-post` jalan dua kali → tidak ada duplikat; template tanggal 31 tidak drift.
- Transfer tidak muncul di perbandingan rencana vs aktual; tarik tunai dan top up e-wallet berjalan sebagai transfer.
- Alert amplop terkirim tepat sekali per ambang.
- Test isolasi data antar akun di setiap endpoint.
- Import ulang mutasi yang sama → 0 baris baru, dua transaksi identik sah tetap masuk.
- Tabel `EXPLAIN` + p95 laporan **dan dashboard** di 200 ribu (+1 juta noise) transaksi: bukti index cukup, tanpa rollup dan tanpa Redis.
- GIF mobile: mode pesawat → catat dan centang → online → tersinkron tanpa duplikat.
- ADR yang menjelaskan kenapa "biasa" itu pilihan sadar (database queue, tanpa Redis, tanpa rollup, `client_id`) beserta kapan keputusan itu harus dibalik.

## Risiko yang Perlu Diwaspadai

- **Float menyelinap:** `parseFloat`, `Number("1.250")` (hasilnya 1.25), pembagian persen di PHP. Satu helper, aturan lint, test.
- **Format angka Indonesia:** titik ribuan, koma desimal. CSV bank berbeda per bank; uji dengan fixture dari bank yang kamu pakai.
- **Angka tidak konsisten antar layar:** Rencana, Beranda, dan Laporan harus memakai `PlanComparisonQuery` yang sama; jangan menulis ulang rumusnya di tempat lain.
- **Dobel hitung aktual:** transaksi yang sudah dicentang dari checklist tidak boleh ikut dihitung di amplop kategorinya (`plan_item_id IS NULL` di query amplop).
- **Unique + soft delete:** `UNIQUE (plan_item_id)` tetap menghitung baris soft-deleted. Batal centang dan hapus transaksi harus menyetel `plan_item_id = NULL`, kalau tidak item tidak bisa dicentang ulang.
- **Urutan kunci:** selalu plan item → akun (urut id untuk transfer). Urutan beda antar Action = deadlock.
- **Timezone:** `occurred_on` dan `plan_items.month` memakai zona waktu user. Scheduler harus menentukan "hari ini" per user.
- **Tanggal akhir bulan:** hitung `due_on` per bulan dari `day_of_month` (dibatasi hari terakhir), bukan bergulir.
- **Dompet tunai meleset:** wajar. Sediakan "sesuaikan saldo" dan tampilkan selisih "tak tercatat" supaya tidak terasa bug.
- **Mobile mengirim ulang dengan `client_id` baru:** `client_id` dibuat saat item masuk antrean dan disimpan bersama item, bukan saat request dikirim.
- **Cek `client_id` sebelum baris dikunci:** dua retry paralel bisa sama-sama lolos. Urutannya: kunci dulu, baru cek `client_id`.
- **Centang offline vs web:** item yang sama dicentang di dua perangkat → yang kedua `409`; layar sinkronisasi harus menjelaskan itu, bukan menampilkan error mentah.
- **Metro + pnpm:** symlink pnpm sering membuat Metro gagal resolve `packages/shared`. Pakai `node-linker=hoisted` dan `watchFolders`; tes bundling sejak minggu 1.
- **Seed tanpa noise:** angka `EXPLAIN` terlihat bagus padahal index `user_id` tidak pernah diuji.
- **Data pribadi asli:** jangan commit CSV mutasi asli, jangan pakai data asli di screenshot, backup + uji restore sebelum dipakai serius, matikan registrasi di produksi.
- **Scope creep:** transfer sebagai item plan, multi-currency, investasi/saham, OCR struk, sinkronisasi API bank, split bill, gamifikasi, parser bank tambahan, push notification. Taruh di "next steps".
- **Waktu UI:** pakai default shadcn/ui dan Recharts. Waktu paling berharga ada di kebenaran saldo, konsistensi angka, test, dan pemakaian nyata.
