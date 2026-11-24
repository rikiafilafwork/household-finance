# Rencana Deploy: VPS Hostinger, Domain, dan Backup

Status: **draf, menunggu keputusan** (lihat § 1). Deploy sendiri ditunda; dokumen ini menyiapkan #41 (deploy + backup harian) dan #73 (uji restore, health check).

## 1. Keputusan yang perlu diambil

| # | Keputusan | Pilihan | Usulan |
| - | --------- | ------- | ------ |
| D1 | Domain | (a) subdomain dari domain yang sudah dimiliki, mis. `uang.domainanda.com`; (b) beli domain baru di Hostinger | **(a)**: gratis, cukup satu A record |
| D2 | Siapa yang bisa membuka aplikasi | (a) publik via HTTPS, registrasi dimatikan; (b) hanya lewat Tailscale (VPN pribadi) | **(a)**: HP & aplikasi mobile langsung jalan; (b) lebih tertutup tapi tiap perangkat wajib Tailscale |
| D3 | Lokasi backup di luar VPS | (a) Cloudflare R2; (b) Backblaze B2; (c) Google Drive | **(a) R2**: gratis 10 GB, tanpa biaya egress, API S3 |
| D4 | Enkripsi file backup | (a) `age` (kunci publik di VPS, kunci privat offline); (b) tanpa enkripsi | **(a)**: data keuangan pribadi; VPS bocor ≠ semua backup terbaca |
| D5 | Paket VPS | KVM 1 (1 vCPU, 4 GB) / KVM 2 | **KVM 1**: 5 container ringan, 1 user. Lokasi server terdekat ke Indonesia (Singapura/Jakarta jika tersedia) |

## 2. Domain & HTTPS

**Satu origin** tetap dipertahankan seperti di dev (`docker/nginx/conf.d`): `/` = SPA, `/api`, `/sanctum`, `/up` = Laravel. Cookie Sanctum tetap first-party tanpa CORS; aplikasi mobile memakai `https://<domain>/api` dengan Bearer token.

```
HP / laptop ──HTTPS──▶ Caddy :443 (sertifikat Let's Encrypt otomatis)
                         │  http
                         ▼
                       nginx :80 (internal) ──▶ SPA statis
                         │  fastcgi
                         ▼
                       app (php-fpm) ── mysql (internal, tanpa port publik)
                       worker, scheduler
```

Langkah domain (D1a):
1. Di DNS domain (hPanel → Domain → DNS Zone, atau DNS penyedia domain): `A uang → <IP VPS>` (dan `AAAA` kalau VPS punya IPv6). TTL 300 saat awal.
2. Caddy di `docker-compose.prod.yml`: port 80/443 publik, `reverse_proxy nginx:80`; nginx **tidak** lagi mem-publish port ke host.
3. Env produksi (`apps/api/.env` di server, tidak di-commit):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://uang.domainanda.com
SANCTUM_STATEFUL_DOMAINS=uang.domainanda.com
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
REGISTRATION_ENABLED=true   # → false setelah akun pertama dibuat
LOG_LEVEL=warning
```

Pengerasan server (sebagian juga #72):
- Ubuntu 24.04, login SSH **kunci saja** (`PasswordAuthentication no`, `PermitRootLogin no`), user non-root di grup `docker`.
- Firewall: hPanel VPS Firewall **dan** `ufw` → hanya 22, 80, 443. MySQL hanya di jaringan Docker.
- `unattended-upgrades` aktif; `fail2ban` untuk SSH.
- Header keamanan di Caddy (HSTS, `X-Content-Type-Options`, `Referrer-Policy`).

## 3. File backup

### Apa yang di-backup

| Data | Cara | Catatan |
| ---- | ---- | ------- |
| Database MySQL | `mysqldump --single-transaction --routines --triggers --no-tablespaces` → `gzip` → `age` | Satu-satunya data penting. Saat ini 0,6 MB; bertahun-tahun pun < 50 MB terkompresi |
| `apps/api/.env` produksi | **sekali** disimpan di password manager | Tidak ada kolom terenkripsi; kehilangan `APP_KEY` hanya membuat sesi web logout. Token mobile di-hash SHA-256, tidak bergantung `APP_KEY` |
| Kunci privat `age` | password manager + cetak/USB offline | **Tanpa kunci ini backup tidak bisa dibuka** |
| Upload CSV import (M6) | tidak di-backup | File sementara; hasilnya sudah ada di DB |

Nama file: `personal-finance_2026-11-25_0130.sql.gz.age` (waktu WIB, urut leksikografis = urut waktu).

### Di mana (aturan 3-2-1)

1. **Asli**: volume `mysql-data` di VPS.
2. **Salinan lokal**: `/var/backups/personal-finance/` di VPS (pemulihan cepat kalau salah hapus data).
3. **Salinan di luar VPS**: bucket R2 `personal-finance-backup` via `rclone` (D3). Token R2 **hanya** boleh tulis ke bucket itu.

Snapshot VPS Hostinger (mingguan, satu slot) dianggap bonus, **bukan** backup: penyedia yang sama, jarang, dan menimpa dirinya sendiri.

### Kapan & berapa lama disimpan

- Harian **01:30 WIB** (sebelum `accounts:reconcile` 02:00 dan jauh dari jam pemakaian).
- Retensi lokal: 7 harian. Retensi R2: 14 harian + 8 mingguan (Minggu) + 12 bulanan (tanggal 1); diterapkan oleh skrip (hapus berdasarkan nama) atau lifecycle rule R2.

### Bagaimana dijalankan

`scripts/backup-db.sh` dijalankan **systemd timer di host** (bukan container scheduler Laravel): backup tetap jalan walau aplikasi rusak, dan `rclone`/`age` tidak perlu masuk image PHP.

```
01:30  set -o pipefail
       docker compose exec -T mysql mysqldump ... | tee >(tail -c 200 > /tmp/dump-tail) | gzip | age -r <pubkey> > /var/backups/...tmp
       → gagal kalau pipeline error, file kosong, atau dump tidak diakhiri "-- Dump completed"
         (dicek sebelum enkripsi, karena kunci privat sengaja tidak ada di server)
       → mv .tmp → nama final (atomik)
       → rclone copy ke r2:personal-finance-backup/daily/
       → bersihkan retensi
       → curl https://hc-ping.com/<uuid>   # hanya kalau semua langkah sukses
```

**Pemantauan**: Healthchecks.io (gratis) mengirim email kalau ping 01:30 tidak datang sampai ±2 jam. Kegagalan backup diam-diam adalah risiko terbesar, bukan ukuran file.

### Restore (bagian dari #73)

`scripts/restore-db.sh <file>` di laptop (yang memegang kunci privat):
1. `rclone copy` file terbaru → `age -d -i key.txt | gunzip`.
2. Impor ke database **sementara** `personal_finance_restore` (bukan DB produksi).
3. `php artisan accounts:reconcile` terhadap DB itu → selisih harus 0; bandingkan jumlah transaksi & saldo dengan aplikasi.
4. Dokumentasikan hasil di `docs/runbooks/restore.md`. Ulangi **tiap 3 bulan** dan setelah upgrade MySQL.

## 4. Urutan pengerjaan (saat deploy tidak ditunda lagi)

| Langkah | Issue |
| ------- | ----- |
| `docker-compose.prod.yml`: target `prod`, tanpa mount kode, SPA dari `web.Dockerfile`, Caddy, nginx tanpa port publik | #41 |
| Siapkan VPS (§ 2 pengerasan), DNS, `.env` produksi, `migrate --force`, buat akun, `REGISTRATION_ENABLED=false` | #41 |
| `scripts/backup-db.sh` + systemd timer + R2 + Healthchecks | #41 |
| Uji restore pertama + runbook, health check `/up` dipantau | #73 |
| `Makefile`: `deploy` (git pull, build, migrate, restart) dan `backup-now` | #41 |

Acceptance #41: aplikasi terbuka dari HP lewat `https://<domain>`, file backup harian muncul di VPS **dan** R2, ping Healthchecks hijau.

## 5. Risiko & catatan

- **Kunci `age` hilang = semua backup tidak berguna.** Simpan di dua tempat sebelum backup pertama.
- Token R2 di VPS: kalau VPS dibobol, penyerang bisa menimpa objek. Aktifkan *object lock*/versioning di bucket kalau tersedia, atau beri token tanpa izin hapus dan biarkan lifecycle rule yang menghapus.
- `onOneServer()` & lock schedule memakai cache `database`: aman untuk satu VPS.
- Zona waktu server dibiarkan UTC; jadwal Laravel sudah memakai `->timezone('Asia/Jakarta')`, timer systemd ditulis eksplisit `Asia/Jakarta`.
- Jangan pakai data asli untuk screenshot README (Architecture.md § risiko).
