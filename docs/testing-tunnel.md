# Testing lewat tunnel + akun demo

Untuk mencoba stack lokal dari perangkat lain (HP, laptop lain) sebelum ada deploy (#41).

## 1. Akun demo

```sh
docker compose exec app php artisan demo:user          # buat sekali
docker compose exec app php artisan demo:user --fresh  # reset ke data awal
```

- Email `demo@example.test`, zona waktu Asia/Jakarta.
- Password acak, ditulis ke `apps/api/storage/app/private/demo-credentials.txt` (di-gitignore, tidak dicetak).
  `--fresh` membuat password baru.
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
- Selama tunnel hidup `APP_DEBUG=false` dan `REGISTRATION_ENABLED=false` (URL publik); dipulihkan saat berhenti.
- `SANCTUM_STATEFUL_DOMAINS` ditambah `*.trycloudflare.com` (permanen) supaya login cookie SPA jalan.
- URL bisa dibuka siapa pun yang tahu alamatnya. Jangan biarkan hidup lama, dan ingat data dogfooding asli
  ada di DB yang sama (hanya bisa dibaca dengan login akun itu).
