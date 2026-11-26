# Desain: Tabungan dengan Tujuan (Savings Goals)

Tanggal: 2026-11-27 · Status: disetujui user (2026-11-27)

## Maksud & alasan

User ingin **tahu berapa tabungan yang dimiliki**, dengan **tujuan dan target yang jelas**, dan supaya
tabungan **tidak terpakai tanpa sadar**. Fitur ini juga sarana belajar mengelola keuangan: melihat
progres, tahu berapa yang perlu disisihkan per bulan.

Konteks user: tabungan ada di **satu rekening** (bank). Aplikasi single-user (pivot 21 Nov 2026).

## Goals

1. Akun bisa ditandai sebagai **rekening tabungan**.
2. Saldo rekening tabungan **dibagi manual ke beberapa tujuan** (alokasi virtual / amplop). Sisanya
   tampil sebagai "belum dialokasikan".
3. Tiap tujuan: nama, target nominal, alokasi saat ini, tenggat opsional → "perlu Rp X/bulan".
4. **Rem**: kalau saldo rekening tabungan < total alokasi, muncul peringatan "Tabungan terpakai Rp X"
   (halaman Tabungan, Beranda, lonceng notifikasi) dan user memilih tujuan mana yang dikurangi.
5. **Uang bebas** di Beranda tidak lagi menghitung saldo rekening tabungan.
6. Relabel UI "Akun" → **"Rekening"** (ambigu dengan akun profil).

## Non-goals (sekarang)

- Setoran tabungan sebagai item di Rencana bulanan (transfer sebagai item plan).
- Tujuan di Laporan/export; tabungan di mobile (Minggu 8 menyusul).
- Tujuan yang mengikuti saldo satu rekening otomatis (cara 2) — bisa ditambah nanti.
- Bunga, investasi, multi-currency.

## Anti-goals

- Transaksi **tidak** wajib memilih tujuan; cara mencatat transaksi, import, transfer, dan reconcile
  tidak berubah.
- Alokasi tidak boleh membuat "uang hantu": total alokasi yang ditampilkan sebagai sehat tidak boleh
  melebihi saldo nyata tanpa peringatan.
- Tidak ada saldo kedua yang bisa melenceng dari transaksi: `accounts.balance` tetap satu-satunya
  kebenaran uang; alokasi hanya label di atasnya.

## Pendekatan yang dipertimbangkan

1. **Alokasi manual (dipilih).** Saldo rekening tabungan dibagi ke tujuan lewat perubahan alokasi.
   Sederhana, cocok untuk satu rekening, tidak menyentuh transaksi. Riwayat per tujuan = log
   perubahan alokasi, bukan transaksi.
2. Satu tujuan = satu rekening/kantong. Progres = saldo akun. Tidak cocok: user hanya punya satu
   rekening tabungan.
3. Setoran/penarikan transaksi memilih tujuan. Riwayat paling lengkap tetapi setiap transaksi lebih
   repot dan perubahan di transaksi/import/reconcile paling besar — bertentangan dengan anti-goal.

User memilih **1**.

## Desain

### Data (API, Laravel)

- `accounts.is_savings` boolean default false. Boleh lebih dari satu akun tabungan (keputusan
  builder: tidak dibatasi satu), tetapi semua tampilan dikelompokkan per rekening tabungan.
- Tabel `savings_goals`: `id`, `user_id` (trait `BelongsToUser`), `account_id` (FK ke akun tabungan
  milik user yang sama), `name` (unik per user), `target_amount` bigint > 0, `allocated_amount`
  bigint ≥ 0 (denormalisasi = Σ log), `target_date` date nullable, `achieved_at` / `archived_at`
  nullable, `sort_order`, timestamps.
- Tabel `savings_allocations` (log): `id`, `user_id`, `savings_goal_id`, `amount` bigint (positif =
  tambah, negatif = kurangi, ≠ 0), `occurred_on` date, `note` nullable, `transaction_id` nullable (diisi oleh "Gunakan"), `client_id` (idempotensi
  seperti transaksi), timestamps. `allocated_amount` = Σ `amount`, diubah atomik (transaksi DB +
  `lockForUpdate` pada akun tabungan) seperti pola saldo.
- Uang: integer rupiah lewat `App\Support\Money`, sama dengan modul lain.

### Aturan

- **Tambah alokasi** ditolak (422) kalau setelahnya Σ alokasi tujuan aktif di rekening itu > saldo
  rekening. **Kurangi** ditolak kalau alokasi tujuan jadi < 0.
- **Belum dialokasikan** = saldo − Σ alokasi (bisa negatif → itu kondisi "terpakai").
- **Tabungan terpakai** = max(0, Σ alokasi − saldo). Dihitung saat dibaca (bukan disimpan).
- Notifikasi `SavingsOverdrawn` saat sebuah transaksi/transfer/penyesuaian saldo membuat rekening
  tabungan masuk kondisi terpakai (sekali per kejadian masuk kondisi, tidak dobel — pola sama dengan
  alert amplop). Dipicu sesudah commit dari Actions transaksi yang menyentuh akun tabungan.
- **Semua "Σ alokasi" = Σ alokasi semua tujuan di rekening itu** (aktif maupun tercapai). Tujuan
  hanya bisa **diarsip atau dihapus kalau alokasinya 0**, jadi tujuan arsip tidak pernah memegang
  uang — tidak ada uang yang bisa disisihkan dua kali. *(keputusan builder-check)*
- Tujuan tercapai (`achieved_at` diset lewat "Tandai selesai", atau tampil "tercapai" saat
  alokasi ≥ target) tetap memegang uangnya dan tetap bisa ditambah/dikurangi.
- **Pindah alokasi antar tujuan** dalam rekening yang sama: satu operasi atomik (dua baris log
  berpasangan, satu transaksi DB), tetap diizinkan walau rekening sedang terpakai. `account_id`
  tujuan tidak bisa diubah. *(keputusan builder-check)*
- **Gunakan tabungan** (dipilih user): tombol "Gunakan" di kartu tujuan. User mengisi nominal,
  kategori pengeluaran, tanggal, catatan → dalam **satu transaksi DB** dibuat transaksi pengeluaran
  dari rekening tabungan (lewat Action transaksi yang ada, saldo atomik) **dan** baris log alokasi
  negatif yang menunjuk transaksi itu (`savings_allocations.transaction_id` nullable). Nominal tidak
  boleh melebihi alokasi tujuan. Karena alokasi turun bersamaan dengan saldo, peringatan terpakai
  tidak muncul. Satu `client_id` untuk keduanya. Kalau transaksi itu nanti dihapus/diedit lewat
  halaman Transaksi, baris log ikut dibalik/disesuaikan dalam transaksi DB yang sama; edit yang
  membuat alokasi < 0 atau memindah transaksi ke rekening lain ditolak (422) selama tertaut ke
  tujuan. *(keputusan builder)*
- Akun tabungan diarsip atau `is_savings` dimatikan: ditolak selama masih ada tujuan dengan
  alokasi > 0.
- **Perlu per bulan** (tenggat diisi) = ceil((target − alokasi) / sisa bulan sampai tenggat,
  termasuk bulan berjalan), minimal 1 bulan; 0 kalau sudah tercapai. Zona waktu user.

### Uang bebas & dashboard

- `free_money` = (saldo akun aktif **non-tabungan**) − `still_to_spend`. Aturan yang sama berlaku
  di halaman **Rencana** (`freeMoney()` di `apps/web/src/components/plan/planMeta.ts`) supaya dua
  halaman tidak beda angka. `still_to_spend` tidak difilter per akun (item rencana dengan akun
  default tabungan tetap dihitung) — sederhana, kasusnya jarang. *(keputusan builder-check)* `balances` mendapat field
  `savings` (Σ saldo akun tabungan); `total` tetap semua akun agar KPI saldo tidak berubah arti.
- Dashboard menambah blok `savings`: total saldo tabungan, total dialokasikan, belum dialokasikan,
  terpakai, dan ringkas tujuan (nama, alokasi, target, persen).

### Endpoint (semua di bawah auth, data ter-isolasi per user)

- `PATCH /accounts/{id}` menerima `is_savings`.
- `GET /savings` — per rekening tabungan: saldo, dialokasikan, belum dialokasikan, terpakai, daftar
  tujuan (+ perlu per bulan).
- `POST /savings-goals`, `PATCH /savings-goals/{id}`, `DELETE /savings-goals/{id}`,
  `POST /savings-goals/{id}/archive`.
- `POST /savings-goals/{id}/use` (`amount`, `category_id`, `occurred_on`, `note`, `client_id`) →
  transaksi pengeluaran + log alokasi negatif.
- `POST /savings-goals/{id}/transfer` (pindah alokasi ke tujuan lain: `to_goal_id`, `amount`,
  `client_id`).
- `POST /savings-goals/{id}/allocations` (`amount` bertanda, `note`, `client_id`) dan
  `GET /savings-goals/{id}/allocations` (riwayat).

### Web

- Menu baru **Tabungan** (`/savings`). Sketsa:

  ```
  Tabungan                         Rekening: BCA Tabungan
  Saldo Rp20.000.000 · Dialokasikan Rp15.000.000 · Belum dialokasikan Rp5.000.000

  Dana darurat   ████████░░  Rp10jt / Rp12jt   83%   [+][−]
  Liburan Bali   █████░░░░░  Rp5jt / Rp10jt    50%   tenggat Jun 2027 · perlu Rp714rb/bln
  [+ Tujuan baru]
  ```
- Kondisi terpakai: banner merah "Tabungan terpakai Rp X" + aksi "Kurangi alokasi" (pilih tujuan).
- Kosong: belum ada rekening tabungan → ajakan menandai rekening di halaman Rekening.
- Halaman Rekening: toggle "Rekening tabungan" di form; badge di daftar.
- Beranda: kartu Tabungan (total, progres tujuan teratas, peringatan terpakai); kartu uang bebas
  memberi keterangan "tidak termasuk tabungan".
- Lonceng: `notificationMeta` untuk `SavingsOverdrawn`.
- **Relabel**: "Akun" → "Rekening" di sidebar, judul halaman, form transaksi, detail transaksi,
  daftar transaksi, Beranda ("Saldo per rekening"), Laporan, Import. Path `/accounts` & API tetap.

### Test

- Feature: CRUD tujuan, aturan tambah/kurangi (batas saldo, tidak < 0), idempotensi `client_id`,
  paralel tambah alokasi tidak melampaui saldo, "Gunakan" atomik (transaksi + log, hapus transaksi
  membalik log, tidak memicu peringatan), pindah antar tujuan, notifikasi terpakai tidak dobel, arsip akun
  tabungan ditolak, `free_money` tanpa akun tabungan, perlu per bulan (akhir bulan, lewat tenggat).
- `DataIsolationTest` mencakup semua route baru (IDOR), termasuk `account_id` milik user lain.
- Konsistensi: `allocated_amount` = Σ log (command reconcile diperluas atau test properti).
- Web: halaman Tabungan, form, banner terpakai, relabel (update test yang mencari label "Akun").

## Diserahkan ke builder

Penamaan class/route detail, bentuk JSON persis, komponen UI, urutan PR/issue (mengikuti pola satu
issue per PR).
