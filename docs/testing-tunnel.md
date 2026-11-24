# Testing lewat tunnel + akun demo

Untuk mencoba stack lokal dari perangkat lain (HP, laptop lain) sebelum ada deploy (#41).

## 1. Akun demo

```sh
docker compose exec app php artisan demo:user          # buat sekali
docker compose exec app php artisan demo:user --fresh  # reset ke data awal

# akun bersih (hanya kategori bawaan), password mudah diketik
docker compose exec app php artisan demo:user --email=coba@example.test --name=Coba --empty --easy-password
```

- Default email `demo@example.test`, zona waktu Asia/Jakarta.
- Kredensial ditulis ke `apps/api/storage/app/private/credentials/<email>.txt` (di-gitignore, tidak dicetak).
  `--fresh` membuat password baru. `--easy-password`: 3 kata + 2 angka (mis. `kata-kata-kata-12`),
  cukup untuk testing karena login dibatasi 5 percobaan/menit; jangan dipakai untuk data asli.
- Isi: 4 akun (BCA, Bank Jago, GoPay, Dompet), 10 template bulanan, rencana 6 bulan terakhir dengan centang,
  transaksi harian, top-up/tarik tunai. Bulan berjalan punya tagihan terlambat (Listrik), gaji jatuh tempo,
  amplop ≥ 80% dan satu yang jebol. Semua lewat Actions → `accounts:reconcile` selisih 0.
- Ditolak di `APP_ENV=production`.

## 2. Tunnel

```sh
docker compose up -d
(cd apps/web && pnpm build)   # nginx menyajikan apps/web/dist
scripts/tunnel.sh             # Ctrl+C untuk berhenti
```

- Cloudflare Quick Tunnel: URL acak `https://*.trycloudflare.com` muncul di log, tanpa akun Cloudflare.
  URL berganti setiap kali dijalankan. Hostname baru kadang butuh ±30 detik sebelum bisa di-resolve.
- Selama tunnel hidup `APP_DEBUG=false` (URL publik); dipulihkan saat berhenti. Registrasi tetap mengikuti
  `.env` supaya alur Daftar bisa diuji; `scripts/tunnel.sh --closed` menutupnya selama tunnel hidup.
- `SANCTUM_STATEFUL_DOMAINS` ditambah `*.trycloudflare.com` (permanen) supaya login cookie SPA jalan.
- URL bisa dibuka siapa pun yang tahu alamatnya. Jangan biarkan hidup lama, dan ingat data dogfooding asli
  ada di DB yang sama (hanya bisa dibaca dengan login akun itu).
