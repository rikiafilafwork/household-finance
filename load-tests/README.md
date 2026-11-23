# Load tests

## `concurrent-writes.js`: tulis paralel ke satu akun

Pertanyaan yang dijawab: **kalau 50 klien menulis ke akun yang sama bersamaan, dan sebagian me-retry dengan `client_id` yang sama, apakah saldo tetap benar dan tidak ada transaksi dobel?**

```sh
make load-test                      # default 50 VU selama 20 detik
make load-test VUS=100 DURATION=60s
```

Alurnya:

1. `LoadTestUserSeeder` membuat user `loadtest@example.com` (idempoten).
2. `setup()` k6 login dengan token mobile, lalu membuat satu akun bersaldo awal Rp1.000.000.000.
3. 50 VU mengirim `POST /transactions` (expense Rp1.000) ke **akun yang sama**. Sebanyak **30%** request memakai `client_id` dari kumpulan 200 id yang dipakai bersama semua VU, jadi retry duplikat terjadi bersamaan dari VU yang berbeda.
4. `teardown()` membaca semua transaksi lewat API, lalu gagal kalau ada `client_id` dobel atau `saldo ≠ saldo awal − Rp1.000 × jumlah baris`.
5. `make` menjalankan `accounts:reconcile`, yang harus melaporkan **selisih 0**.

Threshold k6: `checks rate==1` dan `http_req_failed rate==0`. Satu respons yang bukan 201/200 langsung membuat run gagal.

### Hasil (21 Nov 2026)

| Metrik | Nilai |
| --- | --- |
| Request transaksi | 2.682 (≈123/detik) |
| Baru (`201`) | **2.079** |
| Retry duplikat (`200`, tanpa efek) | **603** |
| Gagal (4xx/5xx) | **0** |
| Baris di DB vs `client_id` unik | 2.079 = 2.079 (tidak ada dobel) |
| Saldo akhir | Rp997.921.000 = 1.000.000.000 − 1.000 × 2.079 ✅ |
| `accounts:reconcile` | **Selisih 0** ✅ |
| Latensi | p50 355 ms · p95 545 ms · maks 741 ms |

Mesin: Apple M1 (8 core, 8 GB), Docker Desktop 29.8 (VM 8 CPU / 4 GB), MySQL 8.4.13, PHP 8.4-fpm. Stack dev: kode di-*bind mount* dari macOS, tanpa tuning php-fpm/opcache.

### Cara membaca angkanya

- **Throughput ini sengaja serial.** Setiap tulisan mengunci baris akun yang sama (`SELECT … FOR UPDATE`), jadi 50 VU antre di satu kunci. Ini harga yang dibayar demi saldo yang benar (Keputusan #3). Latensi ratusan milidetik adalah waktu antre, bukan query yang lambat. Pemakaian nyata (satu orang, beberapa akun) jauh di bawah beban ini.
- **603 retry tidak menyentuh saldo.** Karena `client_id` dicek *setelah* akun terkunci, retry yang datang bersamaan melihat hasil yang pertama dan dijawab 200 (Keputusan #15).
- Pembuktian paralel tingkat proses (termasuk transfer berlawanan arah tanpa deadlock dan retry ke akun berbeda) ada di `apps/api/tests/Concurrency`.
